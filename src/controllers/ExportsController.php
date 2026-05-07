<?php

namespace craft\feedme\controllers;

use Craft;
use craft\elements\User;
use craft\feedme\models\ExportModel;
use craft\feedme\Plugin;
use craft\helpers\FileHelper;
use craft\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Export controller.
 *
 * @property Plugin $module
 */
class ExportsController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * @return Response
     * @throws \Throwable
     * @throws \yii\web\BadRequestHttpException
     */
    public function actionRunExport(): Response
    {
        $this->requirePostRequest();
        $this->requireCpRequest();

        $request = Craft::$app->getRequest();
        $feedId = $request->getRequiredBodyParam('feedId');
        $feed = Plugin::$plugin->feeds->getFeedById($feedId);

        if (!Plugin::$plugin->exports->isFeedExportable($feed)) {
            Craft::$app->getSession()->setError(Craft::t('feed-me', 'This feed type cannot be exported.'));

            return $this->redirect('feed-me/feeds');
        }

        $user = Craft::$app->getUser()->getIdentity();

        if (!$user instanceof User) {
            throw new ForbiddenHttpException(Craft::t('feed-me', 'You must be logged in to export a feed.'));
        }

        $export = Plugin::$plugin->exports->queueExport($feed, $user, $request->getBodyParam('format'));

        Craft::$app->getSession()->setNotice(Craft::t('feed-me', 'Export queued. A download link will be emailed to {email}.', [
            'email' => $user->email,
        ]));

        return $this->redirect('feed-me/feeds');
    }

    /**
     * @param string $token
     * @return Response
     * @throws ForbiddenHttpException
     * @throws NotFoundHttpException
     * @throws \yii\base\Exception
     */
    public function actionDownload(string $token): Response
    {
        $this->requireLogin();

        $export = Plugin::$plugin->exports->getExportByToken($token);

        if (!$export instanceof ExportModel || $export->status !== ExportModel::STATUS_COMPLETE || $export->getIsExpired()) {
            throw new NotFoundHttpException(Craft::t('feed-me', 'Export not found.'));
        }

        $user = Craft::$app->getUser()->getIdentity();

        if (!$user instanceof User || !Plugin::$plugin->exports->canDownloadExport($export, $user)) {
            throw new ForbiddenHttpException(Craft::t('feed-me', 'You do not have permission to download this export.'));
        }

        $filePath = Plugin::$plugin->exports->getExportFilePath($export);

        if (!is_file($filePath)) {
            throw new NotFoundHttpException(Craft::t('feed-me', 'Export file not found.'));
        }

        return Craft::$app->getResponse()->sendFile($filePath, $export->filename, [
            'mimeType' => FileHelper::getMimeTypeByExtension($export->filename),
        ]);
    }
}
