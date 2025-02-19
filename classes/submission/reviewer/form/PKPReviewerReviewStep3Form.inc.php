<?php

/**
 * @file classes/submission/reviewer/form/PKPReviewerReviewStep3Form.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PKPReviewerReviewStep3Form
 * @ingroup submission_reviewer_form
 *
 * @brief Form for Step 3 of a review.
 */

namespace PKP\submission\reviewer\form;

use APP\core\Application;
use APP\log\SubmissionEventLogEntry;
use APP\notification\NotificationManager;
use APP\template\TemplateManager;
use PKP\core\Core;
use PKP\db\DAORegistry;
use PKP\log\SubmissionLog;

use PKP\notification\PKPNotification;
use PKP\reviewForm\ReviewFormElement;
use PKP\security\Role;
use PKP\submission\SubmissionComment;

// FIXME: Add namespacing
import('lib.pkp.controllers.confirmationModal.linkAction.ViewReviewGuidelinesLinkAction');
use ViewReviewGuidelinesLinkAction;

class PKPReviewerReviewStep3Form extends ReviewerReviewForm
{
    /**
     * Constructor.
     *
     * @param $reviewerSubmission ReviewerSubmission
     * @param $reviewAssignment ReviewAssignment
     */
    public function __construct($request, $reviewerSubmission, $reviewAssignment)
    {
        parent::__construct($request, $reviewerSubmission, $reviewAssignment, 3);

        // Validation checks for this form
        $reviewFormElementDao = DAORegistry::getDAO('ReviewFormElementDAO'); /** @var ReviewFormElementDAO $reviewFormElementDao */
        $requiredReviewFormElementIds = $reviewFormElementDao->getRequiredReviewFormElementIds($reviewAssignment->getReviewFormId());
        $this->addCheck(new \PKP\form\validation\FormValidatorCustom($this, 'reviewFormResponses', 'required', 'reviewer.submission.reviewFormResponse.form.responseRequired', function ($reviewFormResponses) use ($requiredReviewFormElementIds) {
            foreach ($requiredReviewFormElementIds as $requiredReviewFormElementId) {
                if (!isset($reviewFormResponses[$requiredReviewFormElementId]) || $reviewFormResponses[$requiredReviewFormElementId] == '') {
                    return false;
                }
            }
            return true;
        }));

        $this->addCheck(new \PKP\form\validation\FormValidatorPost($this));
        $this->addCheck(new \PKP\form\validation\FormValidatorCSRF($this));
    }

    /**
     * @copydoc ReviewerReviewForm::initData
     */
    public function initData()
    {
        $reviewAssignment = $this->getReviewAssignment();

        // Retrieve most recent reviewer comments, one private, one public.
        $submissionCommentDao = DAORegistry::getDAO('SubmissionCommentDAO'); /** @var SubmissionCommentDAO $submissionCommentDao */

        $submissionComments = $submissionCommentDao->getReviewerCommentsByReviewerId($reviewAssignment->getSubmissionId(), $reviewAssignment->getReviewerId(), $reviewAssignment->getId(), true);
        $submissionComment = $submissionComments->next();
        $this->setData('comments', $submissionComment ? $submissionComment->getComments() : '');

        $submissionCommentsPrivate = $submissionCommentDao->getReviewerCommentsByReviewerId($reviewAssignment->getSubmissionId(), $reviewAssignment->getReviewerId(), $reviewAssignment->getId(), false);
        $submissionCommentPrivate = $submissionCommentsPrivate->next();
        $this->setData('commentsPrivate', $submissionCommentPrivate ? $submissionCommentPrivate->getComments() : '');
    }

    //
    // Implement protected template methods from Form
    //
    /**
     * @see Form::readInputData()
     */
    public function readInputData()
    {
        $this->readUserVars(
            ['reviewFormResponses', 'comments', 'recommendation', 'commentsPrivate']
        );
    }

    /**
     * @copydoc ReviewerReviewForm::fetch()
     *
     * @param null|mixed $template
     */
    public function fetch($request, $template = null, $display = false)
    {
        $templateMgr = TemplateManager::getManager($request);
        $reviewAssignment = $this->getReviewAssignment();

        // Assign the objects and data to the template.
        $context = $this->request->getContext();
        $templateMgr->assign([
            'reviewAssignment' => $reviewAssignment,
            'reviewRoundId' => $reviewAssignment->getReviewRoundId(),
            'reviewerRecommendationOptions' => \PKP\submission\reviewAssignment\ReviewAssignment::getReviewerRecommendationOptions(),
        ]);

        if ($reviewAssignment->getReviewFormId()) {

            // Get the review form components
            $reviewFormElementDao = DAORegistry::getDAO('ReviewFormElementDAO'); /** @var ReviewFormElementDAO $reviewFormElementDao */
            $reviewFormResponseDao = DAORegistry::getDAO('ReviewFormResponseDAO'); /** @var ReviewFormResponseDAO $reviewFormResponseDao */
            $reviewFormDao = DAORegistry::getDAO('ReviewFormDAO'); /** @var ReviewFormDAO $reviewFormDao */
            $templateMgr->assign([
                'reviewForm' => $reviewFormDao->getById($reviewAssignment->getReviewFormId(), Application::getContextAssocType(), $context->getId()),
                'reviewFormElements' => $reviewFormElementDao->getByReviewFormId($reviewAssignment->getReviewFormId()),
                'reviewFormResponses' => $reviewFormResponseDao->getReviewReviewFormResponseValues($reviewAssignment->getId()),
                'disabled' => isset($reviewAssignment) && $reviewAssignment->getDateCompleted() != null,
            ]);
        }

        //
        // Assign the link actions
        //
        $viewReviewGuidelinesAction = new ViewReviewGuidelinesLinkAction($request, $reviewAssignment->getStageId());
        if ($viewReviewGuidelinesAction->getGuidelines()) {
            $templateMgr->assign('viewGuidelinesAction', $viewReviewGuidelinesAction);
        }

        return parent::fetch($request, $template, $display);
    }

    /**
     * @see Form::execute()
     */
    public function execute(...$functionParams)
    {
        $reviewAssignment = $this->getReviewAssignment();
        $notificationMgr = new NotificationManager();

        // Save the answers to the review form
        $this->saveReviewForm($reviewAssignment);

        // Send notification
        $submissionDao = DAORegistry::getDAO('SubmissionDAO'); /** @var SubmissionDAO $submissionDao */
        $submission = $submissionDao->getById($reviewAssignment->getSubmissionId());

        $stageAssignmentDao = DAORegistry::getDAO('StageAssignmentDAO'); /** @var StageAssignmentDAO $stageAssignmentDao */
        $stageAssignments = $stageAssignmentDao->getBySubmissionAndStageId($submission->getId(), $submission->getStageId());
        $userGroupDao = DAORegistry::getDAO('UserGroupDAO'); /** @var UserGroupDAO $userGroupDao */
        $receivedList = []; // Avoid sending twice to the same user.

        while ($stageAssignment = $stageAssignments->next()) {
            $userId = $stageAssignment->getUserId();
            $userGroup = $userGroupDao->getById($stageAssignment->getUserGroupId(), $submission->getContextId());

            // Never send reviewer comment notification to users other than mangers and editors.
            if (!in_array($userGroup->getRoleId(), [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR]) || in_array($userId, $receivedList)) {
                continue;
            }

            $notificationMgr->createNotification(
                Application::get()->getRequest(),
                $userId,
                PKPNotification::NOTIFICATION_TYPE_REVIEWER_COMMENT,
                $submission->getContextId(),
                ASSOC_TYPE_REVIEW_ASSIGNMENT,
                $reviewAssignment->getId()
            );

            $receivedList[] = $userId;
        }

        // Set review to next step.
        $this->updateReviewStepAndSaveSubmission($this->getReviewerSubmission());

        // Mark the review assignment as completed.
        $reviewAssignment->setDateCompleted(Core::getCurrentDate());
        $reviewAssignment->stampModified();

        // assign the recommendation to the review assignment, if there was one.
        $reviewAssignment->setRecommendation((int) $this->getData('recommendation'));

        // Persist the updated review assignment.
        $reviewAssignmentDao = DAORegistry::getDAO('ReviewAssignmentDAO'); /** @var ReviewAssignmentDAO $reviewAssignmentDao */
        $reviewAssignmentDao->updateObject($reviewAssignment);

        // Remove the task
        $notificationDao = DAORegistry::getDAO('NotificationDAO'); /** @var NotificationDAO $notificationDao */
        $notificationDao->deleteByAssoc(
            ASSOC_TYPE_REVIEW_ASSIGNMENT,
            $reviewAssignment->getId(),
            $reviewAssignment->getReviewerId(),
            PKPNotification::NOTIFICATION_TYPE_REVIEW_ASSIGNMENT
        );

        // Add log
        $userDao = DAORegistry::getDAO('UserDAO'); /** @var UserDAO $userDao */
        $reviewer = $userDao->getById($reviewAssignment->getReviewerId());
        $request = Application::get()->getRequest();
        SubmissionLog::logEvent(
            $request,
            $submission,
            SubmissionEventLogEntry::SUBMISSION_LOG_REVIEW_READY,
            'log.review.reviewReady',
            [
                'reviewAssignmentId' => $reviewAssignment->getId(),
                'reviewerName' => $reviewer->getFullName(),
                'submissionId' => $reviewAssignment->getSubmissionId(),
                'round' => $reviewAssignment->getRound()
            ]
        );

        parent::execute(...$functionParams);
    }

    /**
     * Save the given answers for later
     */
    public function saveForLater()
    {
        $reviewAssignment = $this->getReviewAssignment();
        $notificationMgr = new NotificationManager();

        // Save the answers to the review form
        $this->saveReviewForm($reviewAssignment);

        // Mark the review assignment as modified.
        $reviewAssignment->stampModified();

        // save the recommendation to the review assignment
        $reviewAssignment->setRecommendation((int) $this->getData('recommendation'));

        // Persist the updated review assignment.
        $reviewAssignmentDao = DAORegistry::getDAO('ReviewAssignmentDAO'); /** @var ReviewAssignmentDAO $reviewAssignmentDao */
        $reviewAssignmentDao->updateObject($reviewAssignment);

        return true;
    }

    /**
     * Save the given answers to the review form
     *
     * @param $reviewAssignment ReviewAssignment
     */
    public function saveReviewForm($reviewAssignment)
    {
        if ($reviewAssignment->getReviewFormId()) {
            $reviewFormResponseDao = DAORegistry::getDAO('ReviewFormResponseDAO'); /** @var ReviewFormResponseDAO $reviewFormResponseDao */
            $reviewFormResponses = $this->getData('reviewFormResponses');
            if (is_array($reviewFormResponses)) {
                foreach ($reviewFormResponses as $reviewFormElementId => $reviewFormResponseValue) {
                    $reviewFormResponse = $reviewFormResponseDao->getReviewFormResponse($reviewAssignment->getId(), $reviewFormElementId);
                    if (!isset($reviewFormResponse)) {
                        $reviewFormResponse = new ReviewFormResponse();
                    }
                    $reviewFormElementDao = DAORegistry::getDAO('ReviewFormElementDAO'); /** @var ReviewFormElementDAO $reviewFormElementDao */
                    $reviewFormElement = $reviewFormElementDao->getById($reviewFormElementId);
                    $elementType = $reviewFormElement->getElementType();
                    switch ($elementType) {
                    case ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_SMALL_TEXT_FIELD:
                    case ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_TEXT_FIELD:
                    case ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_TEXTAREA:
                        $reviewFormResponse->setResponseType('string');
                        $reviewFormResponse->setValue($reviewFormResponseValue);
                        break;
                    case ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_RADIO_BUTTONS:
                    case ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_DROP_DOWN_BOX:
                        $reviewFormResponse->setResponseType('int');
                        $reviewFormResponse->setValue($reviewFormResponseValue);
                        break;
                    case ReviewFormElement::REVIEW_FORM_ELEMENT_TYPE_CHECKBOXES:
                        $reviewFormResponse->setResponseType('object');
                        $reviewFormResponse->setValue($reviewFormResponseValue);
                        break;
                }
                    if ($reviewFormResponse->getReviewFormElementId() != null && $reviewFormResponse->getReviewId() != null) {
                        $reviewFormResponseDao->updateObject($reviewFormResponse);
                    } else {
                        $reviewFormResponse->setReviewFormElementId($reviewFormElementId);
                        $reviewFormResponse->setReviewId($reviewAssignment->getId());
                        $reviewFormResponseDao->insertObject($reviewFormResponse);
                    }
                }
            }
        } else {
            // No review form configured. Use the default form.
            if (strlen($comments = $this->getData('comments')) > 0) {
                // Create a comment with the review.
                $submissionCommentDao = DAORegistry::getDAO('SubmissionCommentDAO'); /** @var SubmissionCommentDAO $submissionCommentDao */
                $submissionComments = $submissionCommentDao->getReviewerCommentsByReviewerId($reviewAssignment->getSubmissionId(), $reviewAssignment->getReviewerId(), $reviewAssignment->getId(), true);
                $comment = $submissionComments->next();

                if (!isset($comment)) {
                    $comment = $submissionCommentDao->newDataObject();
                }

                $comment->setCommentType(SubmissionComment::COMMENT_TYPE_PEER_REVIEW);
                $comment->setRoleId(Role::ROLE_ID_REVIEWER);
                $comment->setAssocId($reviewAssignment->getId());
                $comment->setSubmissionId($reviewAssignment->getSubmissionId());
                $comment->setAuthorId($reviewAssignment->getReviewerId());
                $comment->setComments($comments);
                $comment->setCommentTitle('');
                $comment->setViewable(true);
                $comment->setDatePosted(Core::getCurrentDate());

                // Save or update
                if ($comment->getId() != null) {
                    $submissionCommentDao->updateObject($comment);
                } else {
                    $submissionCommentDao->insertObject($comment);
                }
            }
            unset($comment);

            if (strlen($commentsPrivate = $this->getData('commentsPrivate')) > 0) {
                // Create a comment with the review.
                $submissionCommentDao = DAORegistry::getDAO('SubmissionCommentDAO'); /** @var SubmissionCommentDAO $submissionCommentDao */
                $submissionCommentsPrivate = $submissionCommentDao->getReviewerCommentsByReviewerId($reviewAssignment->getSubmissionId(), $reviewAssignment->getReviewerId(), $reviewAssignment->getId(), false);
                $comment = $submissionCommentsPrivate->next();

                if (!isset($comment)) {
                    $comment = $submissionCommentDao->newDataObject();
                }

                $comment->setCommentType(SubmissionComment::COMMENT_TYPE_PEER_REVIEW);
                $comment->setRoleId(Role::ROLE_ID_REVIEWER);
                $comment->setAssocId($reviewAssignment->getId());
                $comment->setSubmissionId($reviewAssignment->getSubmissionId());
                $comment->setAuthorId($reviewAssignment->getReviewerId());
                $comment->setComments($commentsPrivate);
                $comment->setCommentTitle('');
                $comment->setViewable(false);
                $comment->setDatePosted(Core::getCurrentDate());

                // Save or update
                if ($comment->getId() != null) {
                    $submissionCommentDao->updateObject($comment);
                } else {
                    $submissionCommentDao->insertObject($comment);
                }
            }
            unset($comment);
        }
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\PKP\submission\reviewer\form\PKPReviewerReviewStep3Form', '\PKPReviewerReviewStep3Form');
}
