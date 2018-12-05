<?php

namespace Xfrocks\Api\XFMG\Controller;


use XF\Mvc\ParameterBag;
use Xfrocks\Api\Controller\AbstractController;
use Xfrocks\Api\Util\PageNav;

class Media extends AbstractController
{
    public function actionGetIndex(ParameterBag $params)
    {
        if ($params->media_id) {
            return $this->actionSingle($params->media_id);
        }

        $params = $this->params()
            ->defineOrder([
                'natural' => ['media_date', 'asc'],
                'natural_reverse' => ['media_date', 'desc'],
                'media_rating' => ['rating_avg', 'asc'],
                'media_rating_reverse' => ['rating_avg', 'desc'],
                'media_comment_count' => ['comment_count', 'asc'],
                'media_comment_count_reverse' => ['comment_count', 'desc'],
            ])
            ->definePageNav();

        /** @var \XFMG\Finder\MediaItem $finder */
        $finder = $this->finder('XFMG:MediaItem');
        $params->sortFinder($finder);
        $params->limitFinderByPage($finder);

        $total = $finder->total();
        $items = $total > 0 ? $this->transformFinderLazily($finder) : [];

        $data = [
            'items' => $items,
            'items_total' => $total
        ];

        PageNav::addLinksToData($data, $params, $total, 'items');

        return $this->api($data);
    }

    protected function actionSingle($itemId)
    {
        $item = $this->assertViewableItem($itemId);

        $data = [
            'item' => $this->transformEntityLazily($item)
        ];

        return $this->api($data);
    }

    public function actionPostIndex()
    {
        $params = $this
            ->params()
            ->define('album_id', 'uint', 'id of the target album')
            ->define('category_id', 'uint', 'id of the target category')
            ->define('title', 'str', 'title of the new media')
            ->define('description', 'str', 'description of the new media')
            ->defineFile('file', 'binary data of the attachment');

        if (!empty($params['album_id'])) {
            $container = $this->assertViewableAlbum($params['album_id']);
            $context = ['media_album_id' => $params['album_id']];
        } else if (!empty($params['category_id'])) {
            $container = $this->assertViewableCategory($params['category_id']);
            $context = ['media_category_id' => $params['category_id']];
        } else {
            return $this->noPermission();
        }

        if (!$container->canAddMedia($error)) {
            return $this->noPermission($error);
        }

        /** @var \Xfrocks\Api\ControllerPlugin\Attachment $attachmentPlugin */
        $attachmentPlugin = $this->plugin('Xfrocks\Api:Attachment');
        $tempHash = $attachmentPlugin->getAttachmentTempHash($context);
        $attachment = $attachmentPlugin->doUpload($tempHash, 'xfmg_media', $context);

        /** @var \XFMG\Entity\MediaTemp $tempMedia */
        $mediaTemp = $this->em()->findOne('XFMG:MediaTemp', ['attachment_id' => $attachment->attachment_id]);

        /** @var \XFMG\Service\Media\Creator $creator */
        $creator = $this->service('XFMG:Media\Creator', $mediaTemp);
        $creator->setContainer($container);
        $creator->setTitle($params['title'], $params['description']);
        $creator->setAttachment($attachment->attachment_id, $attachment->temp_hash);

        $creator->checkForSpam();

        if (!$creator->validate($errors)) {
            return $this->error($errors);
        }

        /** @var \XFMG\Entity\MediaItem $item */
        $item = $creator->save();

        // Clear entity cache
        $this->em()->detachEntity($item);
        $this->em()->detachEntity($attachment);

        return $this->actionSingle($item->media_id);
    }

    public function actionPutIndex(ParameterBag $params)
    {
        $item = $this->assertViewableItem($params->media_id);
        if (!$item->canEdit($error)) {
            return $this->noPermission($error);
        }

        $params = $this->params()
            ->define('title', 'str', 'title of the new media')
            ->define('description', 'str', 'description of the new media');

        /** @var \XFMG\Service\Media\Editor $editor */
        $editor = $this->service('XFMG:Media\Editor', $item);
        $editor->setTitle($params['title'], $params['description']);
        $editor->checkForSpam();

        if (!$editor->validate($errors))
        {
            return $this->error($errors);
        }
        /** @var \XFMG\Entity\MediaItem $item */
        $item = $editor->save();

        return $this->actionSingle($item->media_id);
    }

    /**
     * @param int $albumId
     * @param array $extraWith
     * @return \XFMG\Entity\Album
     * @throws \XF\Mvc\Reply\Exception
     */
    protected function assertViewableAlbum($albumId, array $extraWith = [])
    {
        /** @var \XFMG\Entity\Album $album */
        $album = $this->assertRecordExists(
            'XFMG:Album',
            $albumId,
            $extraWith,
            'xfmg_requested_album_not_found'
        );

        if (!$album->canView($error)) {
            throw $this->exception($this->noPermission($error));
        }

        return $album;
    }

    /**
     * @param int $categoryId
     * @param array $extraWith
     * @return \XFMG\Entity\Category
     * @throws \XF\Mvc\Reply\Exception
     */
    protected function assertViewableCategory($categoryId, array $extraWith = [])
    {
        /** @var \XFMG\Entity\Category $category */
        $category = $this->assertRecordExists(
            'XFMG:Category',
            $categoryId,
            $extraWith,
            'xfmg_requested_category_not_found'
        );

        if (!$category->canView($error)) {
            throw $this->exception($this->noPermission($error));
        }

        return $category;
    }

    /**
     * @param int $mediaId
     * @param array $extraWith
     * @return \XFMG\Entity\MediaItem
     * @throws \XF\Mvc\Reply\Exception
     */
    protected function assertViewableItem($mediaId, array $extraWith = [])
    {
        /** @var \XFMG\Entity\MediaItem $item */
        $item = $this->assertRecordExists(
            'XFMG:MediaItem',
            $mediaId,
            $extraWith,
            'xfmg_requested_media_item_not_found'
        );

        if (!$item->canView($error)) {
            throw $this->exception($this->noPermission($error));
        }

        return $item;
    }
}
