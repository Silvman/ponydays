<?php
/*-------------------------------------------------------
*
*   LiveStreet Engine Social Networking
*   Copyright © 2008 Mzhelskiy Maxim
*
*--------------------------------------------------------
*
*   Official site: www.livestreet.ru
*   Contact e-mail: rus.engine@gmail.com
*
*   GNU General Public License, version 2:
*   http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
*
---------------------------------------------------------
*/

namespace App\Modules;

use App\Entities\EntityFavourite;
use App\Entities\EntityTopic;
use App\Entities\EntityTopicQuestionVote;
use App\Entities\EntityTopicRead;
use App\Entities\EntityTopicTag;
use App\Entities\EntityUser;
use App\Mappers\MapperTopic;
use Engine\Config;
use Engine\Engine;
use Engine\LS;
use Engine\Module;
use Engine\Modules\ModuleCache;
use Engine\Modules\ModuleImage;
use Engine\Modules\ModuleLang;
use Engine\Modules\ModuleMessage;
use Zend_Cache;

/**
 * Модуль для работы с топиками
 *
 * @package modules.topic
 * @since   1.0
 */
class ModuleTopic extends Module
{
    /**
     * Объект маппера
     *
     * @var \App\Mappers\MapperTopic
     */
    protected $oMapperTopic;
    /**
     * Объект текущего пользователя
     *
     * @var EntityUser|null
     */
    protected $oUserCurrent = null;
    /**
     * Список типов топика
     *
     * @var array
     */
    protected $aTopicTypes = [
        'topic',
        'link',
        'question',
        'photoset'
    ];

    /**
     * Инициализация
     *
     */
    public function Init()
    {
        $this->oMapperTopic = Engine::MakeMapper(MapperTopic::class);
        $this->oUserCurrent = LS::Make(ModuleUser::class)->GetUserCurrent();
    }

    /**
     * Возвращает список типов топика
     *
     * @return array
     */
    public function GetTopicTypes()
    {
        return $this->aTopicTypes;
    }

    /**
     * Добавляет в новый тип топика
     *
     * @param string $sType Новый тип
     *
     * @return bool
     */
    public function AddTopicType($sType)
    {
        if (!in_array($sType, $this->aTopicTypes)) {
            $this->aTopicTypes[] = $sType;

            return true;
        }

        return false;
    }

    /**
     * Проверяет разрешен ли данный тип топика
     *
     * @param string $sType Тип
     *
     * @return bool
     */
    public function IsAllowTopicType($sType)
    {
        return in_array($sType, $this->aTopicTypes);
    }

    /**
     * Получает дополнительные данные(объекты) для топиков по их ID
     *
     * @param array      $aTopicId   Список ID топиков
     * @param array|null $aAllowData Список типов дополнительных данных, которые нужно подключать к топикам
     *
     * @return array
     */
    public function GetTopicsAdditionalData($aTopicId, $aAllowData = null)
    {
        if (is_null($aAllowData)) {
            $aAllowData =
                ['user' => [], 'blog' => ['owner' => [], 'relation_user'], 'vote', 'favourite', 'comment_new'];
        }
        func_array_simpleflip($aAllowData);
        if (!is_array($aTopicId)) {
            $aTopicId = [$aTopicId];
        }
        /**
         * Получаем "голые" топики
         */
        $aTopics = $this->GetTopicsByArrayId($aTopicId);
        /**
         * Формируем ID дополнительных данных, которые нужно получить
         */
        $aUserId = [];
        $aBlogId = [];
        $aTopicIdQuestion = [];
        $aPhotoMainId = [];
        foreach ($aTopics as $oTopic) {
            if (isset($aAllowData['user'])) {
                $aUserId[] = $oTopic->getUserId();
            }
            if (isset($aAllowData['blog'])) {
                $aBlogId[] = $oTopic->getBlogId();
            }
            if ($oTopic->getType() == 'question') {
                $aTopicIdQuestion[] = $oTopic->getId();
            }
            if ($oTopic->getType() == 'photoset' and $oTopic->getPhotosetMainPhotoId()) {
                $aPhotoMainId[] = $oTopic->getPhotosetMainPhotoId();
            }
        }
        /**
         * Получаем дополнительные данные
         */
        $aTopicsVote = [];
        $aFavouriteTopics = [];
        $aTopicsQuestionVote = [];
        $aTopicsRead = [];
        $aUsers = isset($aAllowData['user']) && is_array($aAllowData['user']) ? LS::Make(ModuleUser::class)
            ->GetUsersAdditionalData($aUserId, $aAllowData['user'])
            : LS::Make(ModuleUser::class)->GetUsersAdditionalData($aUserId);
        $aBlogs = isset($aAllowData['blog']) && is_array($aAllowData['blog']) ? LS::Make(ModuleBlog::class)
            ->GetBlogsAdditionalData($aBlogId, $aAllowData['blog'])
            : LS::Make(ModuleBlog::class)->GetBlogsAdditionalData($aBlogId);
        if (isset($aAllowData['vote']) and $this->oUserCurrent) {
            $aTopicsVote =
                LS::Make(ModuleVote::class)->GetVoteByArray($aTopicId, 'topic', $this->oUserCurrent->getId());
            $aTopicsQuestionVote = $this->GetTopicsQuestionVoteByArray($aTopicIdQuestion, $this->oUserCurrent->getId());
        }
        if (isset($aAllowData['favourite']) and $this->oUserCurrent) {
            $aFavouriteTopics = $this->GetFavouriteTopicsByArray($aTopicId, $this->oUserCurrent->getId());
        }
        if (isset($aAllowData['comment_new']) and $this->oUserCurrent) {
            $aTopicsRead = $this->GetTopicsReadByArray($aTopicId, $this->oUserCurrent->getId());
        }
        $aPhotosetMainPhotos = $this->GetTopicPhotosByArrayId($aPhotoMainId);
        /**
         * Добавляем данные к результату - списку топиков
         */
        foreach ($aTopics as $oTopic) {
            if (isset($aUsers[$oTopic->getUserId()])) {
                $oTopic->setUser($aUsers[$oTopic->getUserId()]);
            } else {
                $oTopic->setUser(null); // или $oTopic->setUser(new ModuleUser_EntityUser());
            }
            if (isset($aBlogs[$oTopic->getBlogId()])) {
                $oTopic->setBlog($aBlogs[$oTopic->getBlogId()]);
            } else {
                $oTopic->setBlog(null); // или $oTopic->setBlog(new ModuleBlog_EntityBlog());
            }
            if (isset($aTopicsVote[$oTopic->getId()])) {
                $oTopic->setVote($aTopicsVote[$oTopic->getId()]);
            } else {
                $oTopic->setVote(null);
            }
            if (isset($aFavouriteTopics[$oTopic->getId()])) {
                $oTopic->setFavourite($aFavouriteTopics[$oTopic->getId()]);
            } else {
                $oTopic->setFavourite(null);
            }
            if (isset($aTopicsQuestionVote[$oTopic->getId()])) {
                $oTopic->setUserQuestionIsVote(true);
            } else {
                $oTopic->setUserQuestionIsVote(false);
            }
            if (isset($aTopicsRead[$oTopic->getId()])) {
                $oTopic->setCountCommentNew(
                    $oTopic->getCountComment() - $aTopicsRead[$oTopic->getId()]->getCommentCountLast()
                );
                $oTopic->setDateRead($aTopicsRead[$oTopic->getId()]->getDateRead());
            } else {
                $oTopic->setCountCommentNew(0);
                $oTopic->setDateRead(date("Y-m-d H:i:s"));
            }
            if (isset($aPhotosetMainPhotos[$oTopic->getPhotosetMainPhotoId()])) {
                $oTopic->setPhotosetMainPhoto($aPhotosetMainPhotos[$oTopic->getPhotosetMainPhotoId()]);
            } else {
                $oTopic->setPhotosetMainPhoto(null);
            }
        }

        return $aTopics;
    }

    /**
     * Получает дополнительные данные(объекты) для удаленных топиков по их ID
     *
     * @param array      $aTopicId   Список ID топиков
     * @param array|null $aAllowData Список типов дополнительных данных, которые нужно подключать к топикам
     *
     * @return array
     */
    public function GetDeletedTopicsAdditionalData($aTopicId, $aAllowData = null)
    {
        if (is_null($aAllowData)) {
            $aAllowData =
                ['user' => [], 'blog' => ['owner' => [], 'relation_user'], 'vote', 'favourite', 'comment_new'];
        }
        func_array_simpleflip($aAllowData);
        if (!is_array($aTopicId)) {
            $aTopicId = [$aTopicId];
        }
        /**
         * Получаем "голые" топики
         */
        $aTopics = $this->GetDeletedTopicsByArrayId($aTopicId);
        /**
         * Формируем ID дополнительных данных, которые нужно получить
         */
        $aUserId = [];
        $aBlogId = [];
        $aTopicIdQuestion = [];
        $aPhotoMainId = [];
        foreach ($aTopics as $oTopic) {
            if (isset($aAllowData['user'])) {
                $aUserId[] = $oTopic->getUserId();
            }
            if (isset($aAllowData['blog'])) {
                $aBlogId[] = $oTopic->getBlogId();
            }
            if ($oTopic->getType() == 'question') {
                $aTopicIdQuestion[] = $oTopic->getId();
            }
            if ($oTopic->getType() == 'photoset' and $oTopic->getPhotosetMainPhotoId()) {
                $aPhotoMainId[] = $oTopic->getPhotosetMainPhotoId();
            }
        }
        /**
         * Получаем дополнительные данные
         */
        $aTopicsVote = [];
        $aFavouriteTopics = [];
        $aTopicsQuestionVote = [];
        $aTopicsRead = [];
        $aUsers = isset($aAllowData['user']) && is_array($aAllowData['user']) ? LS::Make(ModuleUser::class)
            ->GetUsersAdditionalData($aUserId, $aAllowData['user'])
            : LS::Make(ModuleUser::class)->GetUsersAdditionalData($aUserId);
        $aBlogs = isset($aAllowData['blog']) && is_array($aAllowData['blog']) ? LS::Make(ModuleBlog::class)
            ->GetBlogsAdditionalData($aBlogId, $aAllowData['blog'])
            : LS::Make(ModuleBlog::class)->GetBlogsAdditionalData($aBlogId);
        if (isset($aAllowData['vote']) and $this->oUserCurrent) {
            $aTopicsVote =
                LS::Make(ModuleVote::class)->GetVoteByArray($aTopicId, 'topic', $this->oUserCurrent->getId());
            $aTopicsQuestionVote = $this->GetTopicsQuestionVoteByArray($aTopicIdQuestion, $this->oUserCurrent->getId());
        }
        if (isset($aAllowData['favourite']) and $this->oUserCurrent) {
            $aFavouriteTopics = $this->GetFavouriteTopicsByArray($aTopicId, $this->oUserCurrent->getId());
        }
        if (isset($aAllowData['comment_new']) and $this->oUserCurrent) {
            $aTopicsRead = $this->GetTopicsReadByArray($aTopicId, $this->oUserCurrent->getId());
        }
        $aPhotosetMainPhotos = $this->GetTopicPhotosByArrayId($aPhotoMainId);
        /**
         * Добавляем данные к результату - списку топиков
         */
        foreach ($aTopics as $oTopic) {
            if (isset($aUsers[$oTopic->getUserId()])) {
                $oTopic->setUser($aUsers[$oTopic->getUserId()]);
            } else {
                $oTopic->setUser(null); // или $oTopic->setUser(new ModuleUser_EntityUser());
            }
            if (isset($aBlogs[$oTopic->getBlogId()])) {
                $oTopic->setBlog($aBlogs[$oTopic->getBlogId()]);
            } else {
                $oTopic->setBlog(null); // или $oTopic->setBlog(new ModuleBlog_EntityBlog());
            }
            if (isset($aTopicsVote[$oTopic->getId()])) {
                $oTopic->setVote($aTopicsVote[$oTopic->getId()]);
            } else {
                $oTopic->setVote(null);
            }
            if (isset($aFavouriteTopics[$oTopic->getId()])) {
                $oTopic->setFavourite($aFavouriteTopics[$oTopic->getId()]);
            } else {
                $oTopic->setFavourite(null);
            }
            if (isset($aTopicsQuestionVote[$oTopic->getId()])) {
                $oTopic->setUserQuestionIsVote(true);
            } else {
                $oTopic->setUserQuestionIsVote(false);
            }
            if (isset($aTopicsRead[$oTopic->getId()])) {
                $oTopic->setCountCommentNew(
                    $oTopic->getCountComment() - $aTopicsRead[$oTopic->getId()]->getCommentCountLast()
                );
                $oTopic->setDateRead($aTopicsRead[$oTopic->getId()]->getDateRead());
            } else {
                $oTopic->setCountCommentNew(0);
                $oTopic->setDateRead(date("Y-m-d H:i:s"));
            }
            if (isset($aPhotosetMainPhotos[$oTopic->getPhotosetMainPhotoId()])) {
                $oTopic->setPhotosetMainPhoto($aPhotosetMainPhotos[$oTopic->getPhotosetMainPhotoId()]);
            } else {
                $oTopic->setPhotosetMainPhoto(null);
            }
        }

        return $aTopics;
    }

    /**
     * Добавляет топик
     *
     * @param \App\Entities\EntityTopic $oTopic Объект топика
     *
     * @return \App\Entities\EntityTopic|bool
     */
    public function AddTopic(EntityTopic $oTopic)
    {
        if ($sId = $this->oMapperTopic->AddTopic($oTopic)) {
            $oTopic->setId($sId);
            if ($oTopic->getPublish() and $oTopic->getTags()) {
                $aTags = explode(',', $oTopic->getTags());
                foreach ($aTags as $sTag) {
                    $oTag = new EntityTopicTag();
                    $oTag->setTopicId($oTopic->getId());
                    $oTag->setUserId($oTopic->getUserId());
                    $oTag->setBlogId($oTopic->getBlogId());
                    $oTag->setText($sTag);
                    $this->AddTopicTag($oTag);
                }
            }
            //чистим зависимые кеши
            LS::Make(ModuleCache::class)->Clean(
                Zend_Cache::CLEANING_MODE_MATCHING_TAG,
                ['topic_new', "topic_update_user_{$oTopic->getUserId()}", "topic_new_blog_{$oTopic->getBlogId()}"]
            );

            return $oTopic;
        }

        return false;
    }

    /**
     * Добавление тега к топику
     *
     * @param \App\Entities\EntityTopicTag $oTopicTag Объект тега топика
     *
     * @return int
     */
    public function AddTopicTag(EntityTopicTag $oTopicTag)
    {
        return $this->oMapperTopic->AddTopicTag($oTopicTag);
    }

    /**
     * Удаляет теги у топика
     *
     * @param int $sTopicId ID топика
     *
     * @return bool
     */
    public function DeleteTopicTagsByTopicId($sTopicId)
    {
        return $this->oMapperTopic->DeleteTopicTagsByTopicId($sTopicId);
    }

    /**
     * Удаляет топик.
     * Если тип таблиц в БД InnoDB, то удалятся всё связи по топику(комменты,голосования,избранное)
     *
     * @param \App\Entities\EntityTopic|int $oTopicId Объект топика или ID
     *
     * @return bool
     */
    public function DeleteTopic($oTopicId)
    {
        //FIXME: unreachable
        return false;

        /** @noinspection PhpUnreachableStatementInspection */
        /** @var \Engine\Modules\ModuleCache $cache */
        $cache = LS::Make(ModuleCache::class);
        if ($oTopicId instanceof EntityTopic) {
            $sTopicId = $oTopicId->getId();
            $cache->Clean(Zend_Cache::CLEANING_MODE_MATCHING_TAG, ["topic_update_user_{$oTopicId->getUserId()}"]);
        } else {
            $sTopicId = $oTopicId;
        }
        /**
         * Чистим зависимые кеши
         */
        $cache->Clean(Zend_Cache::CLEANING_MODE_MATCHING_TAG, ['topic_update']);
        $cache->Delete("topic_{$sTopicId}");
        /**
         * Список изображений
         */
        $aPhotos = $this->getPhotosByTopicId($sTopicId);
        /**
         * Если топик успешно удален, удаляем связанные данные
         */
        if ($bResult = $this->oMapperTopic->DeleteTopic($sTopicId)) {
            return $this->DeleteTopicAdditionalData($sTopicId, $aPhotos);
        }

        return false;
    }

    /**
     * Удаляет свзяанные с топика данные
     *
     * @param  int $iTopicId ID топика
     *
     * @return bool
     */
    public function DeleteTopicAdditionalData($iTopicId, $aPhotos = [])
    {
        //FIXME: unreachable
        return false;

        /**
         * Чистим зависимые кеши
         */
        /** @noinspection PhpUnreachableStatementInspection */
        /** @var \Engine\Modules\ModuleCache $cache */
        $cache = LS::Make(ModuleCache::class);
        $cache->Clean(Zend_Cache::CLEANING_MODE_MATCHING_TAG, ['topic_update']);
        $cache->Delete("topic_{$iTopicId}");
        /**
         * Удаляем контент топика
         */
        $this->DeleteTopicContentByTopicId($iTopicId);
        /**
         * Удаляем комментарии к топику.
         * При удалении комментариев они удаляются из избранного,прямого эфира и голоса за них
         */
        LS::Make(ModuleComment::class)->DeleteCommentByTargetId($iTopicId, 'topic');
        /**
         * Удаляем топик из избранного
         */
        $this->DeleteFavouriteTopicByArrayId($iTopicId);
        /**
         * Удаляем топик из прочитанного
         */
        $this->DeleteTopicReadByArrayId($iTopicId);
        /**
         * Удаляем голосование к топику
         */
        LS::Make(ModuleVote::class)->DeleteVoteByTarget($iTopicId, 'topic');
        /**
         * Удаляем теги
         */
        $this->DeleteTopicTagsByTopicId($iTopicId);
        /**
         * Удаляем фото у топика фотосета
         */
        if (count($aPhotos)) {
            foreach ($aPhotos as $oPhoto) {
                $this->deleteTopicPhoto($oPhoto);
            }
        }

        return true;
    }

    /**
     * Обновляет топик
     *
     * @param \App\Entities\EntityTopic $oTopic Объект топика
     *
     * @return bool
     */
    public function UpdateTopic(EntityTopic $oTopic)
    {
        /**
         * Получаем топик ДО изменения
         */
        $oTopicOld = $this->GetTopicById($oTopic->getId());
        if ($oTopicOld == null) {
            $oTopicOld = $this->GetDeletedTopicById($oTopic->getId());
        }
        $oTopic->setDateEdit(date("Y-m-d H:i:s"));
        if ($this->oMapperTopic->UpdateTopic($oTopic)) {
            /**
             * Если топик изменил видимость(publish) или локацию (BlogId) или список тегов
             */
            if (($oTopic->getPublish() != $oTopicOld->getPublish()) || ($oTopic->getBlogId() != $oTopicOld->getBlogId())
                || ($oTopic->getTags() != $oTopicOld->getTags())
            ) {
                /**
                 * Обновляем теги
                 */
                $this->DeleteTopicTagsByTopicId($oTopic->getId());
                if ($oTopic->getPublish() and $oTopic->getTags()) {
                    $aTags = explode(',', $oTopic->getTags());
                    foreach ($aTags as $sTag) {
                        $oTag = new EntityTopicTag();
                        $oTag->setTopicId($oTopic->getId());
                        $oTag->setUserId($oTopic->getUserId());
                        $oTag->setBlogId($oTopic->getBlogId());
                        $oTag->setText($sTag);
                        $this->AddTopicTag($oTag);
                    }
                }
            }
            if ($oTopic->getPublish() != $oTopicOld->getPublish()) {
                /**
                 * Обновляем избранное
                 */
                $this->SetFavouriteTopicPublish($oTopic->getId(), $oTopic->getPublish());
                /**
                 * Удаляем комментарий топика из прямого эфира
                 */
                /** @var ModuleComment $comment */
                $comment = LS::Make(ModuleComment::class);
                if ($oTopic->getPublish() == 0) {
                    $comment->DeleteCommentOnlineByTargetId($oTopic->getId(), 'topic');
                }
                /**
                 * Изменяем видимость комментов
                 */
                $comment->SetCommentsPublish($oTopic->getId(), 'topic', $oTopic->getPublish());
            }
            //чистим зависимые кеши
            /** @var \Engine\Modules\ModuleCache $cache */
            $cache = LS::Make(ModuleCache::class);
            $cache->Clean(
                Zend_Cache::CLEANING_MODE_MATCHING_TAG,
                ['topic_update', "topic_update_user_{$oTopic->getUserId()}"]
            );
            $cache->Delete("topic_{$oTopic->getId()}");

            return true;
        }

        return false;
    }

    /**
     * Удаление контента топика по его номеру
     *
     * @param int $iTopicId ID топика
     *
     * @return bool
     */
    public function DeleteTopicContentByTopicId($iTopicId)
    {
        return false;
        //return $this->oMapperTopic->DeleteTopicContentByTopicId($iTopicId);
    }

    /**
     * Получить топик по айдишнику
     *
     * @param int $sId ID топика
     *
     * @return \App\Entities\EntityTopic|null
     */
    public function GetTopicById($sId)
    {
        if (!is_numeric($sId)) {
            return null;
        }
        $aTopics = $this->GetTopicsAdditionalData($sId);
        if (isset($aTopics[$sId])) {
            return $aTopics[$sId];
        }

        return null;
    }

    /**
     * Получить удаленный топик по айдишнику
     *
     * @param int $sId ID топика
     *
     * @return \App\Entities\EntityTopic|null
     */
    public function GetDeletedTopicById($sId)
    {
        if (!is_numeric($sId)) {
            return null;
        }
        $aTopics = $this->GetDeletedTopicsAdditionalData($sId);
        if (isset($aTopics[$sId])) {
            return $aTopics[$sId];
        }

        return null;
    }

    /**
     * Получить список топиков по списку айдишников
     *
     * @param array $aTopicId Список ID топиков
     *
     * @return array
     */
    public function GetTopicsByArrayId($aTopicId)
    {
        if (!$aTopicId) {
            return [];
        }
        if (Config::Get('sys.cache.solid')) {
            return $this->GetTopicsByArrayIdSolid($aTopicId);
        }

        if (!is_array($aTopicId)) {
            $aTopicId = [$aTopicId];
        }
        $aTopicId = array_unique($aTopicId);
        $aTopics = [];
        $aTopicIdNotNeedQuery = [];
        /**
         * Делаем мульти-запрос к кешу
         */
        /** @var \Engine\Modules\ModuleCache $cache */
        $cache = LS::Make(ModuleCache::class);
        $aCacheKeys = func_build_cache_keys($aTopicId, 'topic_');
        if (false !== ($data = $cache->Get($aCacheKeys))) {
            /**
             * проверяем что досталось из кеша
             */
            foreach ($aCacheKeys as $sValue => $sKey) {
                if (array_key_exists($sKey, $data)) {
                    if ($data[$sKey]) {
                        $aTopics[$data[$sKey]->getId()] = $data[$sKey];
                    } else {
                        $aTopicIdNotNeedQuery[] = $sValue;
                    }
                }
            }
        }
        /**
         * Смотрим каких топиков не было в кеше и делаем запрос в БД
         */
        $aTopicIdNeedQuery = array_diff($aTopicId, array_keys($aTopics));
        $aTopicIdNeedQuery = array_diff($aTopicIdNeedQuery, $aTopicIdNotNeedQuery);
        $aTopicIdNeedStore = $aTopicIdNeedQuery;
        if ($data = $this->oMapperTopic->GetTopicsByArrayId($aTopicIdNeedQuery)) {
            foreach ($data as $oTopic) {
                /**
                 * Добавляем к результату и сохраняем в кеш
                 */
                $aTopics[$oTopic->getId()] = $oTopic;
                $cache->Set($oTopic, "topic_{$oTopic->getId()}", [], 60 * 60 * 24 * 4);
                $aTopicIdNeedStore = array_diff($aTopicIdNeedStore, [$oTopic->getId()]);
            }
        }
        /**
         * Сохраняем в кеш запросы не вернувшие результата
         */
        foreach ($aTopicIdNeedStore as $sId) {
            $cache->Set(null, "topic_{$sId}", [], 60 * 60 * 24 * 4);
        }
        /**
         * Сортируем результат согласно входящему массиву
         */
        $aTopics = func_array_sort_by_keys($aTopics, $aTopicId);

        return $aTopics;
    }

    /**
     * Получить список топиков по списку айдишников
     *
     * @param array $aTopicId Список ID топиков
     *
     * @return array
     */
    public function GetDeletedTopicsByArrayId($aTopicId)
    {
        if (!$aTopicId) {
            return [];
        }
        if (!is_array($aTopicId)) {
            $aTopicId = [$aTopicId];
        }
        $aTopicId = array_unique($aTopicId);
        $aTopics = [];
        $aTopicIdNotNeedQuery = [];
        /**
         * Смотрим каких топиков не было в кеше и делаем запрос в БД
         */
        $aTopicIdNeedQuery = array_diff($aTopicId, array_keys($aTopics));
        $aTopicIdNeedQuery = array_diff($aTopicIdNeedQuery, $aTopicIdNotNeedQuery);
        $aTopicIdNeedStore = $aTopicIdNeedQuery;

        /** @var \Engine\Modules\ModuleCache $cache */
        $cache = LS::Make(ModuleCache::class);
        if ($data = $this->oMapperTopic->GetDeletedTopicsByArrayId($aTopicIdNeedQuery)) {
            foreach ($data as $oTopic) {
                /**
                 * Добавляем к результату и сохраняем в кеш
                 */
                $aTopics[$oTopic->getId()] = $oTopic;
                $cache->Set($oTopic, "topic_{$oTopic->getId()}", [], 60 * 60 * 24 * 4);
                $aTopicIdNeedStore = array_diff($aTopicIdNeedStore, [$oTopic->getId()]);
            }
        }
        /**
         * Сохраняем в кеш запросы не вернувшие результата
         */
        foreach ($aTopicIdNeedStore as $sId) {
            $cache->Set(null, "topic_{$sId}", [], 60 * 60 * 24 * 4);
        }
        /**
         * Сортируем результат согласно входящему массиву
         */
        $aTopics = func_array_sort_by_keys($aTopics, $aTopicId);

        return $aTopics;
    }

    /**
     * Получить список топиков по списку айдишников, но используя единый кеш
     *
     * @param array $aTopicId Список ID топиков
     *
     * @return array
     */
    public function GetTopicsByArrayIdSolid($aTopicId)
    {
        if (!is_array($aTopicId)) {
            $aTopicId = [$aTopicId];
        }
        $aTopicId = array_unique($aTopicId);
        $aTopics = [];
        $s = join(',', $aTopicId);

        /** @var \Engine\Modules\ModuleCache $cache */
        $cache = LS::Make(ModuleCache::class);
        if (false === ($data = $cache->Get("topic_id_{$s}"))) {
            $data = $this->oMapperTopic->GetTopicsByArrayId($aTopicId);
            foreach ($data as $oTopic) {
                $aTopics[$oTopic->getId()] = $oTopic;
            }
            $cache->Set($aTopics, "topic_id_{$s}", ["topic_update"], 60 * 60 * 24 * 1);

            return $aTopics;
        }

        return $data;
    }

    /**
     * Получает список топиков из избранного
     *
     * @param  int $sUserId   ID пользователя
     * @param  int $iCurrPage Номер текущей страницы
     * @param  int $iPerPage  Количество элементов на страницу
     *
     * @return array('collection'=>array,'count'=>int)
     */
    public function GetTopicsFavouriteByUserId($sUserId, $iCurrPage, $iPerPage)
    {
        $aCloseTopics = [];
        /**
         * Получаем список идентификаторов избранных записей
         */
        /** @var \App\Modules\ModuleFavourite $fav */
        $fav = LS::Make(ModuleFavourite::class);
        $data = ($this->oUserCurrent && $sUserId == $this->oUserCurrent->getId())
            ? $fav->GetFavouritesByUserId($sUserId, 'topic', $iCurrPage, $iPerPage, $aCloseTopics)
            : $fav->GetFavouriteOpenTopicsByUserId($sUserId, $iCurrPage, $iPerPage);
        /**
         * Получаем записи по переданому массиву айдишников
         */
        $data['collection'] = $this->GetTopicsAdditionalData($data['collection']);

        return $data;
    }

    /**
     * Возвращает число топиков в избранном
     *
     * @param  int $sUserId ID пользователя
     *
     * @return int
     */
    public function GetCountTopicsFavouriteByUserId($sUserId)
    {
        $aCloseTopics = [];

        /** @var \App\Modules\ModuleFavourite $fav */
        $fav = LS::Make(ModuleFavourite::class);

        return ($this->oUserCurrent && $sUserId == $this->oUserCurrent->getId())
            ? $fav->GetCountFavouritesByUserId($sUserId, 'topic', $aCloseTopics)
            : $fav->GetCountFavouriteOpenTopicsByUserId($sUserId);
    }

    /**
     * Список топиков по фильтру
     *
     * @param  array      $aFilter    Фильтр
     * @param  int        $iPage      Номер страницы
     * @param  int        $iPerPage   Количество элементов на страницу
     * @param  array|null $aAllowData Список типов данных для подгрузки в топики
     *
     * @return array('collection'=>array,'count'=>int)
     */
    public function GetTopicsByFilter($aFilter, $iPage = 1, $iPerPage = 10, $aAllowData = null)
    {
        if (!is_numeric($iPage) or $iPage <= 0) {
            $iPage = 1;
        }
        $aFilter = $this->_getModifiedFilter($aFilter);
        $s = serialize($aFilter);

        /** @var \Engine\Modules\ModuleCache $cache */
        $cache = LS::Make(ModuleCache::class);
        if (false === ($data = $cache->Get("topic_filter_{$s}_{$iPage}_{$iPerPage}"))) {
            $data = [
                'collection' => $this->oMapperTopic->GetTopics($aFilter, $iCount, $iPage, $iPerPage),
                'count'      => $iCount
            ];
            $cache->Set(
                $data,
                "topic_filter_{$s}_{$iPage}_{$iPerPage}",
                ['topic_update', 'topic_new'],
                60 * 60 * 24 * 3
            );
        }
        $data['collection'] = $this->GetTopicsAdditionalData($data['collection'], $aAllowData);

        return $data;
    }

    /**
     * Список удаленных топиков по фильтру
     *
     * @param  array      $aFilter    Фильтр
     * @param  int        $iPage      Номер страницы
     * @param  int        $iPerPage   Количество элементов на страницу
     * @param  array|null $aAllowData Список типов данных для подгрузки в топики
     *
     * @return array('collection'=>array,'count'=>int)
     */
    public function GetDeletedTopicsByFilter($aFilter, $iPage = 1, $iPerPage = 10, $aAllowData = null)
    {
        if (!is_numeric($iPage) or $iPage <= 0) {
            $iPage = 1;
        }
        $aFilter = $this->_getModifiedFilter($aFilter);
        $s = serialize($aFilter);
        $data = [
            'collection' => $this->oMapperTopic->GetDeletedTopics($aFilter, $iCount, $iPage, $iPerPage),
            'count'      => $iCount
        ];

        /** @var \Engine\Modules\ModuleCache $cache */
        $cache = LS::Make(ModuleCache::class);
        $cache->Set($data, "topic_filter_{$s}_{$iPage}_{$iPerPage}", ['topic_update', 'topic_new'], 60 * 60 * 24 * 3);
        $data['collection'] = $this->GetDeletedTopicsAdditionalData($data['collection'], $aAllowData);

        return $data;
    }

    /**
     * Количество топиков по фильтру
     *
     * @param array $aFilter Фильтр
     *
     * @return int
     */
    public function GetCountTopicsByFilter($aFilter)
    {
        $aFilter = $this->_getModifiedFilter($aFilter);
        $s = serialize($aFilter);

        /** @var \Engine\Modules\ModuleCache $cache */
        $cache = LS::Make(ModuleCache::class);
        if (false === ($data = $cache->Get("topic_count_{$s}"))) {
            $data = $this->oMapperTopic->GetCountTopics($aFilter);
            $cache->Set($data, "topic_count_{$s}", ['topic_update', 'topic_new'], 60 * 60 * 24 * 1);
        }

        return $data;
    }

    /**
     * Количество черновиков у пользователя
     *
     * @param int $iUserId ID пользователя
     *
     * @return int
     */
    public function GetCountDraftTopicsByUserId($iUserId)
    {
        return $this->GetCountTopicsByFilter(
            [
                'user_id'       => $iUserId,
                'topic_publish' => 0
            ]
        );
    }

    /**
     * Получает список хороших топиков для вывода на главную страницу(из всех блогов, как коллективных так и
     * персональных)
     *
     * @param  int  $iPage            Номер страницы
     * @param  int  $iPerPage         Количество элементов на страницу
     * @param  bool $bAddAccessible   Указывает на необходимость добавить в выдачу топики,
     *                                из блогов доступных пользователю. При указании false,
     *                                в выдачу будут переданы только топики из общедоступных блогов.
     *
     * @return array
     */
    public function GetTopicsGood($iPage, $iPerPage, $bAddAccessible = true)
    {
        $aFilter = [
            'blog_type'     => [
                'personal',
                'open'
            ],
            'topic_publish' => 1,
            'topic_rating'  => [
                'value'         => Config::Get('module.blog.index_good'),
                'type'          => 'top',
                'publish_index' => 1,
            ]
        ];
        /**
         * Если пользователь авторизирован, то добавляем в выдачу
         * закрытые блоги в которых он состоит
         */
        if ($this->oUserCurrent && $bAddAccessible) {
            $aOpenBlogs = LS::Make(ModuleBlog::class)->GetAccessibleBlogsByUser($this->oUserCurrent);
            if (count($aOpenBlogs)) {
                $aFilter['blog_type']['close'] = $aOpenBlogs;
            }
        }

        return $this->GetTopicsByFilter($aFilter, $iPage, $iPerPage);
    }

    /**
     * Получает список новых топиков, ограничение новизны по дате из конфига
     *
     * @param  int  $iPage            Номер страницы
     * @param  int  $iPerPage         Количество элементов на страницу
     * @param  bool $bAddAccessible   Указывает на необходимость добавить в выдачу топики,
     *                                из блогов доступных пользователю. При указании false,
     *                                в выдачу будут переданы только топики из общедоступных блогов.
     *
     * @return array
     */
    public function GetTopicsNew($iPage, $iPerPage, $bAddAccessible = true)
    {
        $sDate = date("Y-m-d H:00:00", time() - Config::Get('module.topic.new_time'));
        $aFilter = [
            'blog_type'     => [
                'personal',
                'open',
            ],
            'topic_publish' => 1,
            'topic_new'     => $sDate,
        ];
        /**
         * Если пользователь авторизирован, то добавляем в выдачу
         * закрытые блоги в которых он состоит
         */
        if ($this->oUserCurrent && $bAddAccessible) {
            $aOpenBlogs = LS::Make(ModuleBlog::class)->GetAccessibleBlogsByUser($this->oUserCurrent);
            if (count($aOpenBlogs)) {
                $aFilter['blog_type']['close'] = $aOpenBlogs;
            }
        }

        return $this->GetTopicsByFilter($aFilter, $iPage, $iPerPage);
    }

    /**
     * Получает список ВСЕХ новых топиков
     *
     * @param  int  $iPage            Номер страницы
     * @param  int  $iPerPage         Количество элементов на страницу
     * @param  bool $bAddAccessible   Указывает на необходимость добавить в выдачу топики,
     *                                из блогов доступных пользователю. При указании false,
     *                                в выдачу будут переданы только топики из общедоступных блогов.
     *
     * @return array
     */
    public function GetTopicsNewAll($iPage, $iPerPage, $bAddAccessible = true)
    {
        $aFilter = [
            'blog_type'     => [
                'personal',
                'open',
            ],
            'topic_publish' => 1,
        ];
        /**
         * Если пользователь авторизирован, то добавляем в выдачу
         * закрытые блоги в которых он состоит
         */
        if ($this->oUserCurrent && $bAddAccessible) {
            $aOpenBlogs = LS::Make(ModuleBlog::class)->GetAccessibleBlogsByUser($this->oUserCurrent);
            if (count($aOpenBlogs)) {
                $aFilter['blog_type']['close'] = $aOpenBlogs;
            }
            if (count($aOpenBlogs)) {
                $aFilter['blog_type']['invite'] = $aOpenBlogs;
            }
        }

        return $this->GetTopicsByFilter($aFilter, $iPage, $iPerPage);
    }

    /**
     * Получает список ВСЕХ обсуждаемых топиков
     *
     * @param  int        $iPage          Номер страницы
     * @param  int        $iPerPage       Количество элементов на страницу
     * @param  int|string $sPeriod        Период в виде секунд или конкретной даты
     * @param  bool       $bAddAccessible Указывает на необходимость добавить в выдачу топики,
     *                                    из блогов доступных пользователю. При указании false,
     *                                    в выдачу будут переданы только топики из общедоступных блогов.
     *
     * @return array
     */
    public function GetTopicsDiscussed($iPage, $iPerPage, $sPeriod = null, $bAddAccessible = true)
    {
        if (is_numeric($sPeriod)) {
            // количество последних секунд
            $sPeriod = date("Y-m-d H:00:00", time() - $sPeriod);
        }

        $aFilter = [
            'blog_type'     => [
                'personal',
                'open',
            ],
            'topic_publish' => 1
        ];
        if ($sPeriod) {
            $aFilter['topic_date_more'] = $sPeriod;
        }
        $aFilter['order'] = ' t.topic_count_comment desc, t.topic_id desc ';
        /**
         * Если пользователь авторизирован, то добавляем в выдачу
         * закрытые блоги в которых он состоит
         */
        if ($this->oUserCurrent && $bAddAccessible) {
            $aOpenBlogs = LS::Make(ModuleBlog::class)->GetAccessibleBlogsByUser($this->oUserCurrent);
            if (count($aOpenBlogs)) {
                $aFilter['blog_type']['close'] = $aOpenBlogs;
            }
        }

        return $this->GetTopicsByFilter($aFilter, $iPage, $iPerPage);
    }

    /**
     * Получает список ВСЕХ рейтинговых топиков
     *
     * @param  int        $iPage          Номер страницы
     * @param  int        $iPerPage       Количество элементов на страницу
     * @param  int|string $sPeriod        Период в виде секунд или конкретной даты
     * @param  bool       $bAddAccessible Указывает на необходимость добавить в выдачу топики,
     *                                    из блогов доступных пользователю. При указании false,
     *                                    в выдачу будут переданы только топики из общедоступных блогов.
     *
     * @return array
     */
    public function GetTopicsTop($iPage, $iPerPage, $sPeriod = null, $bAddAccessible = true)
    {
        if (is_numeric($sPeriod)) {
            // количество последних секунд
            $sPeriod = date("Y-m-d H:00:00", time() - $sPeriod);
        }

        $aFilter = [
            'blog_type'     => [
                'personal',
                'open',
            ],
            'topic_publish' => 1
        ];
        if ($sPeriod) {
            $aFilter['topic_date_more'] = $sPeriod;
        }
        $aFilter['order'] = ['t.topic_rating desc', 't.topic_id desc'];
        /**
         * Если пользователь авторизирован, то добавляем в выдачу
         * закрытые блоги в которых он состоит
         */
        if ($this->oUserCurrent && $bAddAccessible) {
            $aOpenBlogs = LS::Make(ModuleBlog::class)->GetAccessibleBlogsByUser($this->oUserCurrent);
            if (count($aOpenBlogs)) {
                $aFilter['blog_type']['close'] = $aOpenBlogs;
            }
        }

        return $this->GetTopicsByFilter($aFilter, $iPage, $iPerPage);
    }

    /**
     * Получает заданое число последних топиков
     *
     * @param int $iCount Количество
     *
     * @return array
     */
    public function GetTopicsLast($iCount)
    {
        $aFilter = [
            'blog_type'     => [
                'personal',
                'open',
            ],
            'topic_publish' => 1,
        ];
        /**
         * Если пользователь авторизирован, то добавляем в выдачу
         * закрытые блоги в которых он состоит
         */
        if ($this->oUserCurrent) {
            $aOpenBlogs = LS::Make(ModuleBlog::class)->GetAccessibleBlogsByUser($this->oUserCurrent);
            if (count($aOpenBlogs)) {
                $aFilter['blog_type']['close'] = $aOpenBlogs;
            }
        }
        $aReturn = $this->GetTopicsByFilter($aFilter, 1, $iCount);
        if (isset($aReturn['collection'])) {
            return $aReturn['collection'];
        }

        return [];
    }

    /**
     * список топиков из персональных блогов
     *
     * @param int        $iPage     Номер страницы
     * @param int        $iPerPage  Количество элементов на страницу
     * @param string     $sShowType Тип выборки топиков
     * @param string|int $sPeriod   Период в виде секунд или конкретной даты
     *
     * @return array
     */
    public function GetTopicsPersonal($iPage, $iPerPage, $sShowType = 'good', $sPeriod = null)
    {
        if (is_numeric($sPeriod)) {
            // количество последних секунд
            $sPeriod = date("Y-m-d H:00:00", time() - $sPeriod);
        }
        $aFilter = [
            'blog_type'     => [
                'personal',
            ],
            'topic_publish' => 1,
        ];
        if ($sPeriod) {
            $aFilter['topic_date_more'] = $sPeriod;
        }
        switch ($sShowType) {
            case 'good':
                $aFilter['topic_rating'] = [
                    'value' => Config::Get('module.blog.personal_good'),
                    'type'  => 'top',
                ];
                break;
            case 'bad':
                $aFilter['topic_rating'] = [
                    'value' => Config::Get('module.blog.personal_good'),
                    'type'  => 'down',
                ];
                break;
            case 'new':
                $aFilter['topic_new'] = date("Y-m-d H:00:00", time() - Config::Get('module.topic.new_time'));
                break;
            case 'newall':
                // нет доп фильтра
                break;
            case 'discussed':
                $aFilter['order'] = ['t.topic_count_comment desc', 't.topic_id desc'];
                break;
            case 'top':
                $aFilter['order'] = ['t.topic_rating desc', 't.topic_id desc'];
                break;
            default:
                break;
        }

        return $this->GetTopicsByFilter($aFilter, $iPage, $iPerPage);
    }

    /**
     * Получает число новых топиков в персональных блогах
     *
     * @return int
     */
    public function GetCountTopicsPersonalNew()
    {
        $sDate = date("Y-m-d H:00:00", time() - Config::Get('module.topic.new_time'));
        $aFilter = [
            'blog_type'     => [
                'personal',
            ],
            'topic_publish' => 1,
            'topic_new'     => $sDate,
        ];

        return $this->GetCountTopicsByFilter($aFilter);
    }

    /**
     * Получает список топиков по юзеру
     *
     * @param int $sUserId  ID пользователя
     * @param int $iPublish Флаг публикации топика
     * @param int $iPage    Номер страницы
     * @param int $iPerPage Количество элементов на страницу
     *
     * @return array
     */
    public function GetTopicsPersonalByUser($sUserId, $iPublish, $iPage, $iPerPage)
    {
        $aFilter = [
            'topic_publish' => $iPublish,
            'user_id'       => $sUserId,
            'blog_type'     => ['open', 'personal'],
        ];
        /**
         * Если пользователь смотрит свой профиль, то добавляем в выдачу
         * закрытые блоги в которых он состоит
         */
        if ($this->oUserCurrent && $this->oUserCurrent->getId() == $sUserId) {
            $aFilter['blog_type'][] = 'close';
            $aFilter['blog_type'][] = 'invite';
        }

        return $this->GetTopicsByFilter($aFilter, $iPage, $iPerPage);
    }

    /**
     * Возвращает количество топиков которые создал юзер
     *
     * @param int $sUserId  ID пользователя
     * @param int $iPublish Флаг публикации топика
     *
     * @return array
     */
    public function GetCountTopicsPersonalByUser($sUserId, $iPublish)
    {
        $aFilter = [
            'topic_publish' => $iPublish,
            'user_id'       => $sUserId,
            'blog_type'     => ['open', 'personal'],
        ];
        /**
         * Если пользователь смотрит свой профиль, то добавляем в выдачу
         * закрытые блоги в которых он состоит
         */
        if ($this->oUserCurrent && $this->oUserCurrent->getId() == $sUserId) {
            $aFilter['blog_type'][] = 'close';
        }
        $s = serialize($aFilter);

        /** @var \Engine\Modules\ModuleCache $cache */
        $cache = LS::Make(ModuleCache::class);
        if (false === ($data = $cache->Get("topic_count_user_{$s}"))) {
            $data = $this->oMapperTopic->GetCountTopics($aFilter);
            $cache->Set($data, "topic_count_user_{$s}", ["topic_update_user_{$sUserId}"], 60 * 60 * 24);
        }

        return $data;
    }

    /**
     * Получает список топиков из указанного блога
     *
     * @param  int   $iBlogId    ID блога
     * @param  int   $iPage      Номер страницы
     * @param  int   $iPerPage   Количество элементов на страницу
     * @param  array $aAllowData Список типов данных для подгрузки в топики
     * @param  bool  $bIdsOnly   Возвращать только ID или список объектов
     *
     * @return array|bool
     */
    public function GetTopicsByBlogId($iBlogId, $iPage = 0, $iPerPage = 0, $aAllowData = [], $bIdsOnly = true)
    {
        $aFilter = ['blog_id' => $iBlogId];

        if (!$aTopics = $this->GetTopicsByFilter($aFilter, $iPage, $iPerPage, $aAllowData)) {
            return false;
        }

        return ($bIdsOnly)
            ? array_keys($aTopics['collection'])
            : $aTopics;
    }

    /**
     * Список топиков из коллективных блогов
     *
     * @param int    $iPage     Номер страницы
     * @param int    $iPerPage  Количество элементов на страницу
     * @param string $sShowType Тип выборки топиков
     * @param string $sPeriod   Период в виде секунд или конкретной даты
     *
     * @return array
     */
    public function GetTopicsCollective($iPage, $iPerPage, $sShowType = 'good', $sPeriod = null)
    {
        if (is_numeric($sPeriod)) {
            // количество последних секунд
            $sPeriod = date("Y-m-d H:00:00", time() - $sPeriod);
        }
        $aFilter = [
            'blog_type'     => [
                'open',
            ],
            'topic_publish' => 1,
        ];
        if ($sPeriod) {
            $aFilter['topic_date_more'] = $sPeriod;
        }
        switch ($sShowType) {
            case 'good':
                $aFilter['topic_rating'] = [
                    'value' => Config::Get('module.blog.collective_good'),
                    'type'  => 'top',
                ];
                break;
            case 'bad':
                $aFilter['topic_rating'] = [
                    'value' => Config::Get('module.blog.collective_good'),
                    'type'  => 'down',
                ];
                break;
            case 'new':
                $aFilter['topic_new'] = date("Y-m-d H:00:00", time() - Config::Get('module.topic.new_time'));
                break;
            case 'newall':
                // нет доп филGetDeletedTopicsCollectiveьтра
                break;
            case 'discussed':
                $aFilter['order'] = ['t.topic_count_comment desc', 't.topic_id desc'];
                break;
            case 'top':
                $aFilter['order'] = ['t.topic_rating desc', 't.topic_id desc'];
                break;
            default:
                break;
        }
        /**
         * Если пользователь авторизирован, то добавляем в выдачу
         * закрытые блоги в которых он состоит
         */
        if ($this->oUserCurrent) {
            $aOpenBlogs = LS::Make(ModuleBlog::class)->GetAccessibleBlogsByUser($this->oUserCurrent);
            if (count($aOpenBlogs)) {
                $aFilter['blog_type']['close'] = $aOpenBlogs;
            }
        }

        return $this->GetTopicsByFilter($aFilter, $iPage, $iPerPage);
    }

    /**
     * Список удаленных топиков из коллективных блогов
     *
     * @param int    $iPage     Номер страницы
     * @param int    $iPerPage  Количество элементов на страницу
     * @param string $sShowType Тип выборки топиков
     * @param string $sPeriod   Период в виде секунд или конкретной даты
     *
     * @return array
     */
    public function GetDeletedTopicsCollective($iPage, $iPerPage, $sShowType = 'good', $sPeriod = null)
    {
        if (is_numeric($sPeriod)) {
            // количество последних секунд
            $sPeriod = date("Y-m-d H:00:00", time() - $sPeriod);
        }
        $aFilter = [
            'blog_type'     => [
                'open',
            ],
            'topic_publish' => 1,
        ];
        if ($sPeriod) {
            $aFilter['topic_date_more'] = $sPeriod;
        }
        /**
         * Если пользователь авторизирован, то добавляем в выдачу
         * закрытые блоги в которых он состоит
         */
        if ($this->oUserCurrent) {
            $aOpenBlogs = LS::Make(ModuleBlog::class)->GetAccessibleBlogsByUser($this->oUserCurrent);
            if (count($aOpenBlogs)) {
                $aFilter['blog_type']['close'] = $aOpenBlogs;
            }
        }

        return $this->GetDeletedTopicsByFilter($aFilter, $iPage, $iPerPage);
    }

    /**
     * Получает число новых топиков в коллективных блогах
     *
     * @return int
     */
    public function GetCountTopicsCollectiveNew()
    {
        $sDate = date("Y-m-d H:00:00", time() - Config::Get('module.topic.new_time'));
        $aFilter = [
            'blog_type'     => [
                'open',
            ],
            'topic_publish' => 1,
            'topic_new'     => $sDate,
        ];
        /**
         * Если пользователь авторизирован, то добавляем в выдачу
         * закрытые блоги в которых он состоит
         */
        if ($this->oUserCurrent) {
            $aOpenBlogs = LS::Make(ModuleBlog::class)->GetAccessibleBlogsByUser($this->oUserCurrent);
            if (count($aOpenBlogs)) {
                $aFilter['blog_type']['close'] = $aOpenBlogs;
            }
        }

        return $this->GetCountTopicsByFilter($aFilter);
    }

    /**
     * Получает топики по рейтингу и дате
     *
     * @param string $sDate  Дата
     * @param int    $iLimit Количество
     *
     * @return array
     */
    public function GetTopicsRatingByDate($sDate, $iLimit = 20)
    {
        /**
         * Получаем список блогов, топики которых нужно исключить из выдачи
         */

        /** @var ModuleBlog $blog */
        $blog = LS::Make(ModuleBlog::class);
        $aCloseBlogs = ($this->oUserCurrent)
            ? $blog->GetInaccessibleBlogsByUser($this->oUserCurrent)
            : $blog->GetInaccessibleBlogsByUser();

        $s = serialize($aCloseBlogs);

        /** @var \Engine\Modules\ModuleCache $cache */
        $cache = LS::Make(ModuleCache::class);
        if (false === ($data = $cache->Get("topic_rating_{$sDate}_{$iLimit}_{$s}"))) {
            $data = $this->oMapperTopic->GetTopicsRatingByDate($sDate, $iLimit, $aCloseBlogs);
            $cache->Set($data, "topic_rating_{$sDate}_{$iLimit}_{$s}", ['topic_update'], 60 * 60 * 24 * 2);
        }
        $data = $this->GetTopicsAdditionalData($data);

        return $data;
    }

    /**
     * Список топиков из блога
     *
     * @param \App\Entities\EntityBlog $oBlog     Объект блога
     * @param int                      $iPage     Номер страницы
     * @param int                      $iPerPage  Количество элементов на страницу
     * @param string                   $sShowType Тип выборки топиков
     * @param string                   $sPeriod   Период в виде секунд или конкретной даты
     *
     * @return array
     */
    public function GetTopicsByBlog($oBlog, $iPage, $iPerPage, $sShowType = 'good', $sPeriod = null)
    {
        if (is_numeric($sPeriod)) {
            // количество последних секунд
            $sPeriod = date("Y-m-d H:00:00", time() - $sPeriod);
        }
        $aFilter = [
            'topic_publish' => 1,
            'blog_id'       => $oBlog->getId(),
        ];
        if ($sPeriod) {
            $aFilter['topic_date_more'] = $sPeriod;
        }
        switch ($sShowType) {
            case 'good':
                $aFilter['topic_rating'] = [
                    'value' => Config::Get('module.blog.collective_good'),
                    'type'  => 'top',
                ];
                break;
            case 'bad':
                $aFilter['topic_rating'] = [
                    'value' => Config::Get('module.blog.collective_good'),
                    'type'  => 'down',
                ];
                break;
            case 'new':
                $aFilter['topic_new'] = date("Y-m-d H:00:00", time() - Config::Get('module.topic.new_time'));
                break;
            case 'newall':
                // нет доп фильтра
                break;
            case 'discussed':
                $aFilter['order'] = ['t.topic_count_comment desc', 't.topic_id desc'];
                break;
            case 'top':
                $aFilter['order'] = ['t.topic_rating desc', 't.topic_id desc'];
                break;
            default:
                break;
        }

        return $this->GetTopicsByFilter($aFilter, $iPage, $iPerPage);
    }

    /**
     * Список удаленных топиков из блога
     *
     * @param \App\Entities\EntityBlog $oBlog     Объект блога
     * @param int                      $iPage     Номер страницы
     * @param int                      $iPerPage  Количество элементов на страницу
     * @param string                   $sShowType Тип выборки топиков
     * @param string                   $sPeriod   Период в виде секунд или конкретной даты
     *
     * @return array
     */
    public function GetDeletedTopicsByBlog($oBlog, $iPage, $iPerPage, $sShowType = 'good', $sPeriod = null)
    {
        if (is_numeric($sPeriod)) {
            // количество последних секунд
            $sPeriod = date("Y-m-d H:00:00", time() - $sPeriod);
        }
        $aFilter = [
            'topic_publish' => 1,
            'blog_id'       => $oBlog->getId(),
        ];
        if ($sPeriod) {
            $aFilter['topic_date_more'] = $sPeriod;
        }

        return $this->GetDeletedTopicsByFilter($aFilter, $iPage, $iPerPage);
    }

    /**
     * Получает число новых топиков из блога
     *
     * @param \App\Entities\EntityBlog $oBlog Объект блога
     *
     * @return int
     */
    public function GetCountTopicsByBlogNew($oBlog)
    {
        $sDate = date("Y-m-d H:00:00", time() - Config::Get('module.topic.new_time'));
        $aFilter = [
            'topic_publish' => 1,
            'blog_id'       => $oBlog->getId(),
            'topic_new'     => $sDate,

        ];

        return $this->GetCountTopicsByFilter($aFilter);
    }

    /**
     * Получает список топиков по тегу
     *
     * @param  string $sTag           Тег
     * @param  int    $iPage          Номер страницы
     * @param  int    $iPerPage       Количество элементов на страницу
     * @param  bool   $bAddAccessible Указывает на необходимость добавить в выдачу топики,
     *                                из блогов доступных пользователю. При указании false,
     *                                в выдачу будут переданы только топики из общедоступных блогов.
     *
     * @return array
     */
    public function GetTopicsByTag($sTag, $iPage, $iPerPage, $bAddAccessible = true)
    {
        /** @var ModuleBlog $blog */
        $blog = LS::Make(ModuleBlog::class);
        $aCloseBlogs = ($this->oUserCurrent && $bAddAccessible)
            ? $blog->GetInaccessibleBlogsByUser($this->oUserCurrent)
            : $blog->GetInaccessibleBlogsByUser();

        $s = serialize($aCloseBlogs);
        /** @var \Engine\Modules\ModuleCache $cache */
        $cache = LS::Make(ModuleCache::class);
        if (false === ($data = $cache->Get("topic_tag_{$sTag}_{$iPage}_{$iPerPage}_{$s}"))) {
            $data = [
                'collection' => $this->oMapperTopic->GetTopicsByTag($sTag, $aCloseBlogs, $iCount, $iPage, $iPerPage),
                'count'      => $iCount
            ];
            $cache->Set(
                $data,
                "topic_tag_{$sTag}_{$iPage}_{$iPerPage}_{$s}",
                ['topic_update', 'topic_new'],
                60 * 60 * 24 * 2
            );
        }
        $data['collection'] = $this->GetTopicsAdditionalData($data['collection']);

        return $data;
    }

    /**
     * Получает список тегов топиков
     *
     * @param int   $iLimit        Количество
     * @param array $aExcludeTopic Список ID топиков для исключения
     *
     * @return array
     */
    public function GetTopicTags($iLimit, $aExcludeTopic = [])
    {
        $s = serialize($aExcludeTopic);
        /** @var \Engine\Modules\ModuleCache $cache */
        $cache = LS::Make(ModuleCache::class);
        if (false === ($data = $cache->Get("tag_{$iLimit}_{$s}"))) {
            $data = $this->oMapperTopic->GetTopicTags($iLimit, $aExcludeTopic);
            $cache->Set($data, "tag_{$iLimit}_{$s}", ['topic_update', 'topic_new'], 60 * 60 * 24 * 3);
        }

        return $data;
    }

    /**
     * Получает список тегов из топиков открытых блогов (open,personal)
     *
     * @param  int      $iLimit  Количество
     * @param  int|null $iUserId ID пользователя, чью теги получаем
     *
     * @return array
     */
    public function GetOpenTopicTags($iLimit, $iUserId = null)
    {
        /** @var \Engine\Modules\ModuleCache $cache */
        $cache = LS::Make(ModuleCache::class);
        if (false === ($data = $cache->Get("tag_{$iLimit}_{$iUserId}_open"))) {
            $data = $this->oMapperTopic->GetOpenTopicTags($iLimit, $iUserId);
            $cache->Set($data, "tag_{$iLimit}_{$iUserId}_open", ['topic_update', 'topic_new'], 60 * 60 * 24 * 3);
        }

        return $data;
    }

    /**
     * Увеличивает у топика число комментов
     *
     * @param int $sTopicId ID топика
     *
     * @return bool
     */
    public function increaseTopicCountComment($sTopicId)
    {
        /** @var \Engine\Modules\ModuleCache $cache */
        $cache = LS::Make(ModuleCache::class);
        $cache->Delete("topic_{$sTopicId}");
        $cache->Clean(Zend_Cache::CLEANING_MODE_MATCHING_TAG, ["topic_update"]);

        return $this->oMapperTopic->increaseTopicCountComment($sTopicId);
    }

    /**
     * Получает привязку топика к ибранному(добавлен ли топик в избранное у юзера)
     *
     * @param int $sTopicId ID топика
     * @param int $sUserId  ID пользователя
     *
     * @return EntityFavourite
     */
    public function GetFavouriteTopic($sTopicId, $sUserId)
    {
        return LS::Make(ModuleFavourite::class)->GetFavourite($sTopicId, 'topic', $sUserId);
    }

    /**
     * Получить список избранного по списку айдишников
     *
     * @param array $aTopicId Список ID топиков
     * @param int   $sUserId  ID пользователя
     *
     * @return array
     */
    public function GetFavouriteTopicsByArray($aTopicId, $sUserId)
    {
        return LS::Make(ModuleFavourite::class)->GetFavouritesByArray($aTopicId, 'topic', $sUserId);
    }

    /**
     * Получить список избранного по списку айдишников, но используя единый кеш
     *
     * @param array $aTopicId Список ID топиков
     * @param int   $sUserId  ID пользователя
     *
     * @return array
     */
    public function GetFavouriteTopicsByArraySolid($aTopicId, $sUserId)
    {
        return LS::Make(ModuleFavourite::class)->GetFavouritesByArraySolid($aTopicId, 'topic', $sUserId);
    }

    /**
     * Добавляет топик в избранное
     *
     * @param \App\Entities\EntityFavourite $oFavouriteTopic Объект избранного
     *
     * @return bool
     */
    public function AddFavouriteTopic(EntityFavourite $oFavouriteTopic)
    {
        return LS::Make(ModuleFavourite::class)->AddFavourite($oFavouriteTopic);
    }

    /**
     * Удаляет топик из избранного
     *
     * @param EntityFavourite $oFavouriteTopic Объект избранного
     *
     * @return bool
     */
    public function DeleteFavouriteTopic(EntityFavourite $oFavouriteTopic)
    {
        return LS::Make(ModuleFavourite::class)->DeleteFavourite($oFavouriteTopic);
    }

    /**
     * Устанавливает переданный параметр публикации таргета (топика)
     *
     * @param  int $sTopicId ID топика
     * @param  int $iPublish Флаг публикации топика
     *
     * @return bool
     */
    public function SetFavouriteTopicPublish($sTopicId, $iPublish)
    {
        return LS::Make(ModuleFavourite::class)->SetFavouriteTargetPublish($sTopicId, 'topic', $iPublish);
    }

    /**
     * Удаляет топики из избранного по списку
     *
     * @param  array $aTopicId Список ID топиков
     *
     * @return bool
     */
    public function DeleteFavouriteTopicByArrayId($aTopicId)
    {
        return LS::Make(ModuleFavourite::class)->DeleteFavouriteByTargetId($aTopicId, 'topic');
    }

    /**
     * Получает список тегов по первым буквам тега
     *
     * @param string $sTag   Тэг
     * @param int    $iLimit Количество
     *
     * @return bool
     */
    public function GetTopicTagsByLike($sTag, $iLimit)
    {
        /** @var \Engine\Modules\ModuleCache $cache */
        $cache = LS::Make(ModuleCache::class);
        if (false === ($data = $cache->Get("tag_like_{$sTag}_{$iLimit}"))) {
            $data = $this->oMapperTopic->GetTopicTagsByLike($sTag, $iLimit);
            $cache->Set($data, "tag_like_{$sTag}_{$iLimit}", ["topic_update", "topic_new"], 60 * 60 * 24 * 3);
        }

        return $data;
    }

    /**
     * Обновляем/устанавливаем дату прочтения топика, если читаем его первый раз то добавляем
     *
     * @param \App\Entities\EntityTopicRead $oTopicRead Объект факта чтения топика
     *
     * @return bool
     */
    public function SetTopicRead(EntityTopicRead $oTopicRead)
    {
        /** @var \Engine\Modules\ModuleCache $cache */
        $cache = LS::Make(ModuleCache::class);
        if ($this->GetTopicRead($oTopicRead->getTopicId(), $oTopicRead->getUserId())) {
            $cache->Delete("topic_read_{$oTopicRead->getTopicId()}_{$oTopicRead->getUserId()}");
            $cache->Clean(Zend_Cache::CLEANING_MODE_MATCHING_TAG, ["topic_read_user_{$oTopicRead->getUserId()}"]);
            $this->oMapperTopic->UpdateTopicRead($oTopicRead);
        } else {
            $cache->Delete("topic_read_{$oTopicRead->getTopicId()}_{$oTopicRead->getUserId()}");
            $cache->Clean(Zend_Cache::CLEANING_MODE_MATCHING_TAG, ["topic_read_user_{$oTopicRead->getUserId()}"]);
            $this->oMapperTopic->AddTopicRead($oTopicRead);
        }

        return true;
    }

    /**
     * Получаем дату прочтения топика юзером
     *
     * @param int $sTopicId ID топика
     * @param int $sUserId  ID пользователя
     *
     * @return \App\Entities\EntityTopicRead|null
     */
    public function GetTopicRead($sTopicId, $sUserId)
    {
        $data = $this->GetTopicsReadByArray($sTopicId, $sUserId);
        if (isset($data[$sTopicId])) {
            return $data[$sTopicId];
        }

        return null;
    }

    /**
     * Удаляет записи о чтении записей по списку идентификаторов
     *
     * @param  array|int $aTopicId Список ID топиков
     *
     * @return bool
     */
    public function DeleteTopicReadByArrayId($aTopicId)
    {
        if (!is_array($aTopicId)) {
            $aTopicId = [$aTopicId];
        }

        return $this->oMapperTopic->DeleteTopicReadByArrayId($aTopicId);
    }

    /**
     * Получить список просмотром/чтения топиков по списку айдишников
     *
     * @param array $aTopicId Список ID топиков
     * @param int   $sUserId  ID пользователя
     *
     * @return array
     */
    public function GetTopicsReadByArray($aTopicId, $sUserId)
    {
        if (!$aTopicId) {
            return [];
        }
        if (Config::Get('sys.cache.solid')) {
            return $this->GetTopicsReadByArraySolid($aTopicId, $sUserId);
        }
        if (!is_array($aTopicId)) {
            $aTopicId = [$aTopicId];
        }
        $aTopicId = array_unique($aTopicId);
        $aTopicsRead = [];
        $aTopicIdNotNeedQuery = [];
        /**
         * Делаем мульти-запрос к кешу
         */
        /** @var \Engine\Modules\ModuleCache $cache */
        $cache = LS::Make(ModuleCache::class);
        $aCacheKeys = func_build_cache_keys($aTopicId, 'topic_read_', '_'.$sUserId);
        if (false !== ($data = $cache->Get($aCacheKeys))) {
            /**
             * проверяем что досталось из кеша
             */
            foreach ($aCacheKeys as $sValue => $sKey) {
                if (array_key_exists($sKey, $data)) {
                    if ($data[$sKey]) {
                        $aTopicsRead[$data[$sKey]->getTopicId()] = $data[$sKey];
                    } else {
                        $aTopicIdNotNeedQuery[] = $sValue;
                    }
                }
            }
        }
        /**
         * Смотрим каких топиков не было в кеше и делаем запрос в БД
         */
        $aTopicIdNeedQuery = array_diff($aTopicId, array_keys($aTopicsRead));
        $aTopicIdNeedQuery = array_diff($aTopicIdNeedQuery, $aTopicIdNotNeedQuery);
        $aTopicIdNeedStore = $aTopicIdNeedQuery;
        if ($data = $this->oMapperTopic->GetTopicsReadByArray($aTopicIdNeedQuery, $sUserId)) {
            foreach ($data as $oTopicRead) {
                /**
                 * Добавляем к результату и сохраняем в кеш
                 */
                $aTopicsRead[$oTopicRead->getTopicId()] = $oTopicRead;
                $cache->Set(
                    $oTopicRead,
                    "topic_read_{$oTopicRead->getTopicId()}_{$oTopicRead->getUserId()}",
                    [],
                    60 * 60 * 24 * 4
                );
                $aTopicIdNeedStore = array_diff($aTopicIdNeedStore, [$oTopicRead->getTopicId()]);
            }
        }
        /**
         * Сохраняем в кеш запросы не вернувшие результата
         */
        foreach ($aTopicIdNeedStore as $sId) {
            $cache->Set(null, "topic_read_{$sId}_{$sUserId}", [], 60 * 60 * 24 * 4);
        }
        /**
         * Сортируем результат согласно входящему массиву
         */
        $aTopicsRead = func_array_sort_by_keys($aTopicsRead, $aTopicId);

        return $aTopicsRead;
    }

    /**
     * Получить список просмотром/чтения топиков по списку айдишников, но используя единый кеш
     *
     * @param array $aTopicId Список ID топиков
     * @param int   $sUserId  ID пользователя
     *
     * @return array
     */
    public function GetTopicsReadByArraySolid($aTopicId, $sUserId)
    {
        if (!is_array($aTopicId)) {
            $aTopicId = [$aTopicId];
        }
        $aTopicId = array_unique($aTopicId);
        $aTopicsRead = [];
        $s = join(',', $aTopicId);
        /** @var \Engine\Modules\ModuleCache $cache */
        $cache = LS::Make(ModuleCache::class);
        if (false === ($data = $cache->Get("topic_read_{$sUserId}_id_{$s}"))) {
            $data = $this->oMapperTopic->GetTopicsReadByArray($aTopicId, $sUserId);
            foreach ($data as $oTopicRead) {
                $aTopicsRead[$oTopicRead->getTopicId()] = $oTopicRead;
            }
            $cache->Set(
                $aTopicsRead,
                "topic_read_{$sUserId}_id_{$s}",
                ["topic_read_user_{$sUserId}"],
                60 * 60 * 24 * 1
            );

            return $aTopicsRead;
        }

        return $data;
    }

    /**
     * Проверяет голосовал ли юзер за топик-вопрос
     *
     * @param int $sTopicId ID топика
     * @param int $sUserId  ID пользователя
     *
     * @return \App\Entities\EntityTopicQuestionVote|null
     */
    public function GetTopicQuestionVote($sTopicId, $sUserId)
    {
        $data = $this->GetTopicsQuestionVoteByArray($sTopicId, $sUserId);
        if (isset($data[$sTopicId])) {
            return $data[$sTopicId];
        }

        return null;
    }

    /**
     * Получить список голосований в топике-опросе по списку айдишников
     *
     * @param array $aTopicId Список ID топиков
     * @param int   $sUserId  ID пользователя
     *
     * @return array
     */
    public function GetTopicsQuestionVoteByArray($aTopicId, $sUserId)
    {
        if (!$aTopicId) {
            return [];
        }
        if (Config::Get('sys.cache.solid')) {
            return $this->GetTopicsQuestionVoteByArraySolid($aTopicId, $sUserId);
        }
        if (!is_array($aTopicId)) {
            $aTopicId = [$aTopicId];
        }
        $aTopicId = array_unique($aTopicId);
        $aTopicsQuestionVote = [];
        $aTopicIdNotNeedQuery = [];
        /**
         * Делаем мульти-запрос к кешу
         */
        /** @var \Engine\Modules\ModuleCache $cache */
        $cache = LS::Make(ModuleCache::class);
        $aCacheKeys = func_build_cache_keys($aTopicId, 'topic_question_vote_', '_'.$sUserId);
        if (false !== ($data = $cache->Get($aCacheKeys))) {
            /**
             * проверяем что досталось из кеша
             */
            foreach ($aCacheKeys as $sValue => $sKey) {
                if (array_key_exists($sKey, $data)) {
                    if ($data[$sKey]) {
                        $aTopicsQuestionVote[$data[$sKey]->getTopicId()] = $data[$sKey];
                    } else {
                        $aTopicIdNotNeedQuery[] = $sValue;
                    }
                }
            }
        }
        /**
         * Смотрим каких топиков не было в кеше и делаем запрос в БД
         */
        $aTopicIdNeedQuery = array_diff($aTopicId, array_keys($aTopicsQuestionVote));
        $aTopicIdNeedQuery = array_diff($aTopicIdNeedQuery, $aTopicIdNotNeedQuery);
        $aTopicIdNeedStore = $aTopicIdNeedQuery;
        if ($data = $this->oMapperTopic->GetTopicsQuestionVoteByArray($aTopicIdNeedQuery, $sUserId)) {
            foreach ($data as $oTopicVote) {
                /**
                 * Добавляем к результату и сохраняем в кеш
                 */
                $aTopicsQuestionVote[$oTopicVote->getTopicId()] = $oTopicVote;
                $cache->Set(
                    $oTopicVote,
                    "topic_question_vote_{$oTopicVote->getTopicId()}_{$oTopicVote->getVoterId()}",
                    [],
                    60 * 60 * 24 * 4
                );
                $aTopicIdNeedStore = array_diff($aTopicIdNeedStore, [$oTopicVote->getTopicId()]);
            }
        }
        /**
         * Сохраняем в кеш запросы не вернувшие результата
         */
        foreach ($aTopicIdNeedStore as $sId) {
            $cache->Set(null, "topic_question_vote_{$sId}_{$sUserId}", [], 60 * 60 * 24 * 4);
        }
        /**
         * Сортируем результат согласно входящему массиву
         */
        $aTopicsQuestionVote = func_array_sort_by_keys($aTopicsQuestionVote, $aTopicId);

        return $aTopicsQuestionVote;
    }

    /**
     * Получить список голосований в топике-опросе по списку айдишников, но используя единый кеш
     *
     * @param array $aTopicId Список ID топиков
     * @param int   $sUserId  ID пользователя
     *
     * @return array
     */
    public function GetTopicsQuestionVoteByArraySolid($aTopicId, $sUserId)
    {
        if (!is_array($aTopicId)) {
            $aTopicId = [$aTopicId];
        }
        $aTopicId = array_unique($aTopicId);
        $aTopicsQuestionVote = [];
        $s = join(',', $aTopicId);
        /** @var \Engine\Modules\ModuleCache $cache */
        $cache = LS::Make(ModuleCache::class);
        if (false === ($data = $cache->Get("topic_question_vote_{$sUserId}_id_{$s}"))) {
            $data = $this->oMapperTopic->GetTopicsQuestionVoteByArray($aTopicId, $sUserId);
            foreach ($data as $oTopicVote) {
                $aTopicsQuestionVote[$oTopicVote->getTopicId()] = $oTopicVote;
            }
            $cache->Set(
                $aTopicsQuestionVote,
                "topic_question_vote_{$sUserId}_id_{$s}",
                ["topic_question_vote_user_{$sUserId}"],
                60 * 60 * 24 * 1
            );

            return $aTopicsQuestionVote;
        }

        return $data;
    }

    /**
     * Добавляет факт голосования за топик-вопрос
     *
     * @param \App\Entities\EntityTopicQuestionVote $oTopicQuestionVote Объект голосования в топике-опросе
     *
     * @return bool
     */
    public function AddTopicQuestionVote(EntityTopicQuestionVote $oTopicQuestionVote)
    {
        /** @var \Engine\Modules\ModuleCache $cache */
        $cache = LS::Make(ModuleCache::class);
        $cache->Delete("topic_question_vote_{$oTopicQuestionVote->getTopicId()}_{$oTopicQuestionVote->getVoterId()}");
        $cache->Clean(
            Zend_Cache::CLEANING_MODE_MATCHING_TAG,
            ["topic_question_vote_user_{$oTopicQuestionVote->getVoterId()}"]
        );

        return $this->oMapperTopic->AddTopicQuestionVote($oTopicQuestionVote);
    }

    /**
     * Получает топик по уникальному хешу(текст топика)
     *
     * @param int    $sUserId
     * @param string $sHash
     *
     * @return \App\Entities\EntityTopic|null
     */
    public function GetTopicUnique($sUserId, $sHash)
    {
        $sId = $this->oMapperTopic->GetTopicUnique($sUserId, $sHash);

        return $this->GetTopicById($sId);
    }

    /**
     * Рассылает уведомления о новом топике подписчикам блога
     *
     * @param \App\Entities\EntityBlog  $oBlog      Объект блога
     * @param \App\Entities\EntityTopic $oTopic     Объект топика
     * @param EntityUser                $oUserTopic Объект пользователя
     */
    public function SendNotifyTopicNew($oBlog, $oTopic, $oUserTopic)
    {
        $aBlogUsersResult = LS::Make(ModuleBlog::class)->GetBlogUsersByBlogId(
            $oBlog->getId(),
            null,
            null
        ); // нужно постранично пробегаться по всем
        $aBlogUsers = $aBlogUsersResult['collection'];
        foreach ($aBlogUsers as $oBlogUser) {
            if ($oBlogUser->getUserId() == $oUserTopic->getId()) {
                continue;
            }
            LS::Make(ModuleNotify::class)->SendTopicNewToSubscribeBlog(
                $oBlogUser->getUser(),
                $oTopic,
                $oBlog,
                $oUserTopic
            );
        }
        //отправляем создателю блога
        if ($oBlog->getOwnerId() != $oUserTopic->getId()) {
            LS::Make(ModuleNotify::class)->SendTopicNewToSubscribeBlog(
                $oBlog->getOwner(),
                $oTopic,
                $oBlog,
                $oUserTopic
            );
        }
    }

    /**
     * Возвращает список последних топиков пользователя, опубликованных не более чем $iTimeLimit секунд назад
     *
     * @param  int   $sUserId     ID пользователя
     * @param  int   $iTimeLimit  Число секунд
     * @param  int   $iCountLimit Количество
     * @param  array $aAllowData  Список типов данных для подгрузки в топики
     *
     * @return array
     */
    public function GetLastTopicsByUserId($sUserId, $iTimeLimit, $iCountLimit = 1, $aAllowData = [])
    {
        $aFilter = [
            'topic_publish' => 1,
            'user_id'       => $sUserId,
            'topic_new'     => date("Y-m-d H:i:s", time() - $iTimeLimit),
        ];
        $aTopics = $this->GetTopicsByFilter($aFilter, 1, $iCountLimit, $aAllowData);

        return $aTopics;
    }

    /**
     * Перемещает топики в другой блог
     *
     * @param  array $aTopics Список ID топиков
     * @param  int   $sBlogId ID блога
     *
     * @return bool
     */
    public function MoveTopicsByArrayId($aTopics, $sBlogId)
    {
        /** @var \Engine\Modules\ModuleCache $cache */
        $cache = LS::Make(ModuleCache::class);
        $cache->Clean(Zend_Cache::CLEANING_MODE_MATCHING_TAG, ["topic_update", "topic_new_blog_{$sBlogId}"]);
        if ($res = $this->oMapperTopic->MoveTopicsByArrayId($aTopics, $sBlogId)) {
            // перемещаем теги
            $this->oMapperTopic->MoveTopicsTagsByArrayId($aTopics, $sBlogId);
            /** @var \App\Modules\ModuleComment $comment */
            $comment = LS::Make(ModuleComment::class);
            // меняем target parent у комментов
            $comment->UpdateTargetParentByTargetId($sBlogId, 'topic', $aTopics);
            // меняем target parent у комментов в прямом эфире
            $comment->UpdateTargetParentByTargetIdOnline($sBlogId, 'topic', $aTopics);

            return $res;
        }

        return false;
    }

    /**
     * Перемещает топики в другой блог
     *
     * @param  int $sBlogId    ID старого блога
     * @param  int $sBlogIdNew ID нового блога
     *
     * @return bool
     */
    public function MoveTopics($sBlogId, $sBlogIdNew)
    {
        /** @var \Engine\Modules\ModuleCache $cache */
        $cache = LS::Make(ModuleCache::class);
        $cache->Clean(
            Zend_Cache::CLEANING_MODE_MATCHING_TAG,
            ["topic_update", "topic_new_blog_{$sBlogId}", "topic_new_blog_{$sBlogIdNew}"]
        );
        if ($res = $this->oMapperTopic->MoveTopics($sBlogId, $sBlogIdNew)) {
            // перемещаем теги
            $this->oMapperTopic->MoveTopicsTags($sBlogId, $sBlogIdNew);
            /** @var ModuleComment $comment */
            $comment = LS::Make(ModuleComment::class);
            // меняем target parent у комментов
            $comment->MoveTargetParent($sBlogId, 'topic', $sBlogIdNew);
            // меняем target parent у комментов в прямом эфире
            $comment->MoveTargetParentOnline($sBlogId, 'topic', $sBlogIdNew);

            return $res;
        }

        return false;
    }

    /**
     * Загрузка изображений при написании топика
     *
     * @param  array                    $aFile Массив $_FILES
     * @param  \App\Entities\EntityUser $oUser Объект пользователя
     *
     * @return string|bool
     */
    public function UploadTopicImageFile($aFile, $oUser)
    {
        if (!is_array($aFile) || !isset($aFile['tmp_name'])) {
            return false;
        }

        $sFileTmp = Config::Get('sys.cache.dir').func_generator();
        if (!move_uploaded_file($aFile['tmp_name'], $sFileTmp)) {
            return false;
        }
        /** @var ModuleImage $image */
        $image = LS::Make(ModuleImage::class);
        $sDirUpload = $image->GetIdDir($oUser->getId());
        $aParams = $image->BuildParams('topic');
        $dir = Config::Get('static_server');
        if (!str_ends_with($dir, "/")) $dir .= "/";
        $hash = hash_file("sha1", $sFileTmp);

        if ($sFileImage = $image->Resize(
            $sFileTmp,
            $sDirUpload,
            $hash,
            Config::Get('view.img_max_width'),
            Config::Get('view.img_max_height'),
            Config::Get('view.img_resize_width'),
            null,
            true,
            $aParams
        )) {
            @unlink($sFileTmp);

            $s3 = LS::Make(ModuleS3::class);
            $s3->UploadImage(str_replace($dir, "", $sFileImage), $sFileImage);

            @unlink($sFileImage);
            return $image->GetWebPath($sFileImage);
        }

        @unlink($sFileTmp);
        return false;
    }

    /**
     * Загрузка изображений по переданному URL
     *
     * @param  string     $sUrl URL изображения
     * @param  EntityUser $oUser
     *
     * @return string|int
     */
    public function UploadTopicImageUrl($sUrl, $oUser, $secure = true)
    {
        if ($secure) {
            $host = parse_url($sUrl, PHP_URL_HOST);
            if ($host === 'localhost'
                || (
                    (filter_var($host, FILTER_VALIDATE_IP) !== false)
                    && (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
                        === false)
                )
            ) {
                return ModuleImage::UPLOAD_IMAGE_ERROR;
            }
        }

        $sUrl = str_replace(Config::Get('static_web'), Config::Get('static_inner'), $sUrl);
        $ch = curl_init();
        // Url
        curl_setopt($ch, CURLOPT_URL, $sUrl);
        // Browser/user agent
        curl_setopt(
            $ch,
            CURLOPT_USERAGENT,
            "Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:42.0) Gecko/20100101 Firefox/42.0"
        );
        // Automatically follow Location: headers (ie redirects)
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        // Protocol whitelist
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS | CURLPROTO_FTP | CURLPROTO_FTPS);
        // Auto set the referer in the event of a redirect
        curl_setopt($ch, CURLOPT_AUTOREFERER, true);
        // Make sure we dont get stuck in a loop
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        // 10s timeout time for cURL connection
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        // allow https verification if true
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        // check common name and verify with host name
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        // Set SSL version
        curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_DEFAULT);
        // Return data to variable
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // Set buffer to 100k
        curl_setopt($ch, CURLOPT_BUFFERSIZE, 1024 * 100);
        // Manual progress handling
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        // Abort upload too large files
        curl_setopt(
            $ch,
            CURLOPT_PROGRESSFUNCTION,
            function (
                $DownloadSize,
                $Downloaded,
                $UploadSize,
                $Uploaded
            ) {
                return 0;
            }
        );
        $data = curl_exec($ch);
        $error = curl_error($ch);
        if ($error) {
            if (curl_errno($ch) == CURLE_ABORTED_BY_CALLBACK) {
                return ModuleImage::UPLOAD_IMAGE_ERROR_SIZE;
            }

            return ModuleImage::UPLOAD_IMAGE_ERROR_READ;
        }
        curl_close($ch);
        if (!$data) {
            return ModuleImage::UPLOAD_IMAGE_ERROR;
        }
        /**
         * Создаем tmp-файл, для временного хранения изображения
         */
        $sFileTmp = Config::Get('sys.cache.dir').func_generator();
        $fp = fopen($sFileTmp, 'w');
        if (!fwrite($fp, $data)) {
            return ModuleImage::UPLOAD_IMAGE_ERROR;
        }
        fclose($fp);

        /** @var \Engine\Modules\ModuleImage $image */
        $image = LS::Make(ModuleImage::class);
        $sDirSave = $image->GetIdDir($oUser->getId());
        $aParams = $image->BuildParams('topic');
        /**
         * Передаем изображение на обработку
         */
        $dir = Config::Get('static_server');
        if (!str_ends_with($dir, "/")) $dir .= "/";
        $hash = hash_file("sha1", $sFileTmp);
        $type = substr($sUrl, strrpos($sUrl, '.') + 1);
        if ($qmark = strrpos($type, '?')) {
            $type = substr($type, 0, $qmark);
        }

        if ($sFileImg = $image->Resize(
            $sFileTmp,
            $sDirSave,
            $hash,
            Config::Get('view.img_max_width'),
            Config::Get('view.img_max_height'),
            Config::Get('view.img_resize_width'),
            null,
            true,
            $aParams
        )
        ) {
            @unlink($sFileTmp);

            $s3 = LS::Make(ModuleS3::class);
            $s3->UploadImage(str_replace($dir, "", $sFileImg), $sFileImg);

            @unlink($sFileImg);
            return $image->GetWebPath($sFileImg);
        }

        @unlink($sFileTmp);

        return ModuleImage::UPLOAD_IMAGE_ERROR_SIZE;
    }

    /**
     * Загрузка изображений по переданному URL
     *
     * @param  string     $sUrl URL изображения
     * @param  EntityUser $oUser
     *
     * @return string|int
     */
    public function UploadTopicImageBase64($sBase64, $oUser)
    {
        $data = explode(',', $sBase64);

        $ext = preg_match('/image\/(.+);/', $data[0])[0][0];
        $img_data = base64_decode($data[1]);

        if (!$img_data) {
            return ModuleImage::UPLOAD_IMAGE_ERROR;
        }
        /**
         * Создаем tmp-файл, для временного хранения изображения
         */
        $sFileTmp = Config::Get('sys.cache.dir').func_generator();
        $fp = fopen($sFileTmp, 'w');
        if (!fwrite($fp, $img_data)) {
            return ModuleImage::UPLOAD_IMAGE_ERROR;
        }
        fclose($fp);

        /** @var \Engine\Modules\ModuleImage $image */
        $image = LS::Make(ModuleImage::class);
        $sDirSave = $image->GetIdDir($oUser->getId());
        $aParams = $image->BuildParams('topic');
        /**
         * Передаем изображение на обработку
         */
        $dir = Config::Get('static_server');
        if (!str_ends_with($dir, "/")) $dir .= "/";
        $hash = hash_file("sha1", $sFileTmp);
        $fullname = $hash.".".$ext;
        if ($sFileImg = $image->Resize(
            $sFileTmp,
            $sDirSave,
            $hash,
            Config::Get('view.img_max_width'),
            Config::Get('view.img_max_height'),
            Config::Get('view.img_resize_width'),
            null,
            true,
            $aParams
        )
        ) {
            @unlink($sFileTmp);

            $s3 = LS::Make(ModuleS3::class);
            $s3->UploadImage(str_replace($dir, "", $sFileImg), $sFileImg);

            @unlink($sFileImg);
            return $image->GetWebPath($sFileImg);
        }

        @unlink($sFileTmp);

        return ModuleImage::UPLOAD_IMAGE_ERROR;
    }

    /**
     * Возвращает список фотографий к топику-фотосет по списку id фоток
     *
     * @param array $aPhotoId Список ID фото
     *
     * @return array
     */
    public function GetTopicPhotosByArrayId($aPhotoId)
    {
        if (!$aPhotoId) {
            return [];
        }
        if (!is_array($aPhotoId)) {
            $aPhotoId = [$aPhotoId];
        }
        $aPhotoId = array_unique($aPhotoId);
        $aPhotos = [];
        $s = join(',', $aPhotoId);
        /** @var \Engine\Modules\ModuleCache $cache */
        $cache = LS::Make(ModuleCache::class);
        if (false === ($data = $cache->Get("photoset_photo_id_{$s}"))) {
            $data = $this->oMapperTopic->GetTopicPhotosByArrayId($aPhotoId);
            foreach ($data as $oPhoto) {
                $aPhotos[$oPhoto->getId()] = $oPhoto;
            }
            $cache->Set($aPhotos, "photoset_photo_id_{$s}", ["photoset_photo_update"], 60 * 60 * 24 * 1);

            return $aPhotos;
        }

        return $data;
    }

    /**
     * Добавить к топику изображение
     *
     * @param \App\Entities\EntityTopicPhoto $oPhoto Объект фото к топику-фотосету
     *
     * @return \App\Entities\EntityTopicPhoto|bool
     */
    public function addTopicPhoto($oPhoto)
    {
        if ($sId = $this->oMapperTopic->addTopicPhoto($oPhoto)) {
            $oPhoto->setId($sId);
            /** @var \Engine\Modules\ModuleCache $cache */
            $cache = LS::Make(ModuleCache::class);
            $cache->Clean(Zend_Cache::CLEANING_MODE_MATCHING_TAG, ["photoset_photo_update"]);

            return $oPhoto;
        }

        return false;
    }

    /**
     * Получить изображение из фотосета по его id
     *
     * @param int $sId ID фото
     *
     * @return \App\Entities\EntityTopicPhoto|null
     */
    public function getTopicPhotoById($sId)
    {
        $aPhotos = $this->GetTopicPhotosByArrayId($sId);
        if (isset($aPhotos[$sId])) {
            return $aPhotos[$sId];
        }

        return null;
    }

    /**
     * Получить список изображений из фотосета по id топика
     *
     * @param int      $iTopicId ID топика
     * @param int|null $iFromId  ID с которого начинать выборку
     * @param int|null $iCount   Количество
     *
     * @return array
     */
    public function getPhotosByTopicId($iTopicId, $iFromId = null, $iCount = null)
    {
        return $this->oMapperTopic->getPhotosByTopicId($iTopicId, $iFromId, $iCount);
    }

    /**
     * Получить список изображений из фотосета по временному коду
     *
     * @param string $sTargetTmp Временный ключ
     *
     * @return array
     */
    public function getPhotosByTargetTmp($sTargetTmp)
    {
        return $this->oMapperTopic->getPhotosByTargetTmp($sTargetTmp);
    }

    /**
     * Получить число изображений из фотосета по id топика
     *
     * @param int $iTopicId ID топика
     *
     * @return int
     */
    public function getCountPhotosByTopicId($iTopicId)
    {
        return $this->oMapperTopic->getCountPhotosByTopicId($iTopicId);
    }

    /**
     * Получить число изображений из фотосета по id топика
     *
     * @param string $sTargetTmp Временный ключ
     *
     * @return int
     */
    public function getCountPhotosByTargetTmp($sTargetTmp)
    {
        return $this->oMapperTopic->getCountPhotosByTargetTmp($sTargetTmp);
    }

    /**
     * Обновить данные по изображению
     *
     * @param \App\Entities\EntityTopicPhoto $oPhoto Объект фото
     */
    public function updateTopicPhoto($oPhoto)
    {
        /** @var \Engine\Modules\ModuleCache $cache */
        $cache = LS::Make(ModuleCache::class);
        $cache->Clean(Zend_Cache::CLEANING_MODE_MATCHING_TAG, ["photoset_photo_update"]);
        $this->oMapperTopic->updateTopicPhoto($oPhoto);
    }

    /**
     * Удалить изображение
     *
     * @param \App\Entities\EntityTopicPhoto $oPhoto Объект фото
     */
    public function deleteTopicPhoto($oPhoto)
    {
        /** @var \Engine\Modules\ModuleCache $cache */
        $cache = LS::Make(ModuleCache::class);
        $cache->Clean(Zend_Cache::CLEANING_MODE_MATCHING_TAG, ["photoset_photo_update"]);
        $this->oMapperTopic->deleteTopicPhoto($oPhoto->getId());

        /** @var \Engine\Modules\ModuleImage $image */
        $image = LS::Make(ModuleImage::class);
        $image->RemoveFile($image->GetServerPath($oPhoto->getWebPath()));
        $aSizes = Config::Get('module.topic.photoset.size');
        // Удаляем все сгенерированные миниатюры основываясь на данных из конфига.
        foreach ($aSizes as $aSize) {
            $sSize = $aSize['w'];
            if ($aSize['crop']) {
                $sSize .= 'crop';
            }
            $image->RemoveFile($image->GetServerPath($oPhoto->getWebPath($sSize)));
        }
    }

    /**
     * Загрузить изображение
     *
     * @param array $aFile Массив $_FILES
     *
     * @return string|bool
     */
    public function UploadTopicPhoto($aFile)
    {
        if (!is_array($aFile) || !isset($aFile['tmp_name'])) {
            return false;
        }

        $sFileName = func_generator(10);
        $sPath = Config::Get('path.uploads.images').'/topic/'.date('Y/m/d').'/';

        if (!is_dir(Config::Get('path.root.server').$sPath)) {
            mkdir(Config::Get('path.root.server').$sPath, 0755, true);
        }

        $sFileTmp = Config::Get('path.root.server').$sPath.$sFileName;
        if (!move_uploaded_file($aFile['tmp_name'], $sFileTmp)) {
            return false;
        }

        /** @var \Engine\Modules\ModuleImage $image */
        $image = LS::Make(ModuleImage::class);
        $aParams = $image->BuildParams('photoset');

        $oImage = $image->CreateImageObject($sFileTmp);
        /**
         * Если объект изображения не создан,
         * возвращаем ошибку
         */
        /** @var ModuleMessage $message */
        $message = LS::Make(ModuleMessage::class);
        /** @var \Engine\Modules\ModuleLang $lang */
        $lang = LS::Make(ModuleLang::class);
        if ($sError = $oImage->get_last_error()) {
            // Вывод сообщения об ошибки, произошедшей при создании объекта изображения
            $message->AddError($sError, $lang->Get('error'));
            @unlink($sFileTmp);

            return false;
        }
        /**
         * Превышает максимальные размеры из конфига
         */
        if (($oImage->get_image_params('width') > Config::Get('view.img_max_width')) or ($oImage->get_image_params(
                    'height'
                ) > Config::Get('view.img_max_height'))
        ) {
            $message->AddError($lang->Get('topic_photoset_error_size'), LS::Make(ModuleLang::class)->Get('error'));
            @unlink($sFileTmp);

            return false;
        }
        /**
         * Добавляем к загруженному файлу расширение
         */
        $sFile = $sFileTmp.'.'.$oImage->get_image_params('format');
        rename($sFileTmp, $sFile);

        $aSizes = Config::Get('module.topic.photoset.size');
        foreach ($aSizes as $aSize) {
            /**
             * Для каждого указанного в конфиге размера генерируем картинку
             */
            $sNewFileName = $sFileName.'_'.$aSize['w'];
            $oImage = $image->CreateImageObject($sFile);
            if ($aSize['crop']) {
                $image->CropProportion($oImage, $aSize['w'], $aSize['h'], true);
                $sNewFileName .= 'crop';
            }
            $image->Resize(
                $sFile,
                $sPath,
                $sNewFileName,
                Config::Get('view.img_max_width'),
                Config::Get('view.img_max_height'),
                $aSize['w'],
                $aSize['h'],
                true,
                $aParams,
                $oImage
            );
        }
        $sFilePathOld = $image->GetServerPath($sFile);
        $sServer = rtrim(str_replace(DIRECTORY_SEPARATOR, '/', Config::Get('path.root.server')), '/');
        $sStatic = rtrim(str_replace(DIRECTORY_SEPARATOR, '/', Config::Get('static_server')), '/');
        $sFilePathNew = str_replace($sServer.'/', $sStatic.'/', $sFilePathOld);
        @rename(
            str_replace('/', DIRECTORY_SEPARATOR, $sFilePathOld),
            str_replace('/', DIRECTORY_SEPARATOR, $sFilePathNew)
        );

        return $image->GetWebPath($sFilePathNew);
    }

    /**
     * Пересчитывает счетчик избранных топиков
     *
     * @return bool
     */
    public function RecalculateFavourite()
    {
        return $this->oMapperTopic->RecalculateFavourite();
    }

    /**
     * Пересчитывает счетчики голосований
     *
     * @return bool
     */
    public function RecalculateVote()
    {
        return $this->oMapperTopic->RecalculateVote();
    }

    /**
     * Алиас для корректной работы ORM
     *f
     *
     * @param array $aTopocId Список ID топиков
     *
     * @return array
     */
    public function GetTopicItemsByArrayId($aTopocId)
    {
        return $this->GetTopicsByArrayId($aTopocId);
    }

    public function UpdateControlLock(EntityTopic $oTopic)
    {
        if ($this->oMapperTopic->UpdateControlLock($oTopic)) {
            //чистим зависимые кеши
            /** @var \Engine\Modules\ModuleCache $cache */
            $cache = LS::Make(ModuleCache::class);
            $cache->Clean(Zend_Cache::CLEANING_MODE_MATCHING_TAG, ['topic_update']);
            $cache->Delete("topic_{$oTopic->getId()}");

            return true;
        }

        return false;
    }

    /**
     * Modify filter with ignored users
     *
     * @param array $aFilter
     *
     * @return array
     */
    protected function _getModifiedFilter(array $aFilter)
    {
        if ($this->oUserCurrent) {
            $aIgnoredUser = LS::Make(ModuleUser::class)->GetIgnoredUsersByUser(
                $this->oUserCurrent->getId(),
                ModuleUser::TYPE_IGNORE_TOPICS
            );
            if (count($aIgnoredUser)) {
                if (isset($aFilter['user_id'])) {
                    //leave posibility view topics throu profile
                    if (is_array($aFilter['user_id'])) {
                        $aFilter['user_id'] = array_diff($aFilter['user_id'], $aIgnoredUser);
                        if (!count($aFilter['user_id'])) {
                            $aFilter['not_user_id'] = $aIgnoredUser;
                        }
                    }
                } else {
                    $aFilter['not_user_id'] = $aIgnoredUser;
                }
            }
        }

        return $aFilter;
    }
}
