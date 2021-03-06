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

use App\Entities\EntityStreamEvent;
use App\Mappers\MapperStream;
use Engine\Config;
use Engine\Engine;
use Engine\LS;
use Engine\Module;

/**
 * Модуль потока событий на сайте
 *
 * @package modules.stream
 * @since   1.0
 */
class ModuleStream extends Module
{
    /**
     * Объект маппера
     *
     * @var \App\Mappers\MapperStream
     */
    protected $oMapper = null;
    /**
     * Список дефолтных типов событий, они добавляются каждому пользователю при регистрации
     *
     * @var array
     */
    protected $aEventDefaultTypes = [
        'add_wall',
        'add_topic',
        'add_comment',
        'add_blog',
        'vote_topic',
        'add_friend'
    ];
    /**
     * Типы событий
     *
     * @var array
     */
    protected $aEventTypes = [
        'add_wall'     => ['related' => 'wall', 'unique' => true],
        'add_topic'    => ['related' => 'topic', 'unique' => true],
        'add_comment'  => ['related' => 'comment', 'unique' => true],
        'add_blog'     => ['related' => 'blog', 'unique' => true],
        'vote_topic'   => ['related' => 'topic'],
        'vote_comment' => ['related' => 'comment'],
        'vote_blog'    => ['related' => 'blog'],
        'vote_user'    => ['related' => 'user'],
        'add_friend'   => ['related' => 'user', 'unique_user' => true],
        'join_blog'    => ['related' => 'blog', 'unique_user' => true]
    ];

    /**
     * Инициализация модуля
     */
    public function Init()
    {
        $this->oMapper = Engine::MakeMapper(MapperStream::class);
    }

    /**
     * Возвращает все типы событий
     *
     * @return array
     */
    public function getEventTypes()
    {
        return $this->aEventTypes;
    }

    /**
     * Возвращает типы событий с учетом фильтра(доступности)
     *
     * @param array|null $aTypes Список типов
     *
     * @return array
     */
    public function getEventTypesFilter($aTypes = null)
    {
        if (is_null($aTypes)) {
            $aTypes = array_keys($this->getEventTypes());
        }
        if (Config::Get('module.stream.disable_vote_events')) {
            foreach ($aTypes as $i => $sType) {
                if (substr($sType, 0, 4) == 'vote') {
                    unset ($aTypes[$i]);
                }
            }
        }

        return $aTypes;
    }

    /**
     * Добавляет новый тип события, метод для расширения списка событий плагинами
     *
     * @param string $sName   Название типа
     * @param array  $aParams Параметры
     *
     * @return bool
     */
    public function AddEventType($sName, $aParams)
    {
        if (!array_key_exists($sName, $this->aEventTypes)) {
            $this->aEventTypes[$sName] = $aParams;

            return true;
        }

        return false;
    }

    /**
     * Проверка допустимого типа событий
     *
     * @param string $sType Тип
     *
     * @return bool
     */
    public function IsAllowEventType($sType)
    {
        if (!is_string($sType)) {
            return false;
        }

        return array_key_exists($sType, $this->aEventTypes);
    }

    /**
     * Добавление события в БД
     *
     * @param \App\Entities\EntityStreamEvent $oObject Объект события
     *
     * @return \App\Entities\EntityStreamEvent|bool
     */
    public function AddEvent($oObject)
    {
        if ($iId = $this->oMapper->AddEvent($oObject)) {
            $oObject->setId($iId);

            return $oObject;
        }

        return false;
    }

    /**
     * Обновление события
     *
     * @param \App\Entities\EntityStreamEvent $oObject Объект события
     *
     * @return int
     */
    public function UpdateEvent($oObject)
    {
        return $this->oMapper->UpdateEvent($oObject);
    }

    /**
     * Получает событие по типу и его ID
     *
     * @param string   $sEventType Тип
     * @param int      $iTargetId  ID владельца события
     * @param int|null $iUserId    ID пользователя
     *
     * @return \App\Entities\EntityStreamEvent
     */
    public function GetEventByTarget($sEventType, $iTargetId, $iUserId = null)
    {
        return $this->oMapper->GetEventByTarget($sEventType, $iTargetId, $iUserId);
    }

    /**
     * Запись события в ленту
     *
     * @param int    $iUserId    ID пользователя
     * @param string $sEventType Тип события
     * @param int    $iTargetId  ID владельца
     * @param int    $iPublish   Статус
     *
     * @return bool
     */
    public function Write($iUserId, $sEventType, $iTargetId, $iPublish = 1)
    {
        $iPublish = (int)$iPublish;
        if (!$this->IsAllowEventType($sEventType)) {
            return false;
        }
        $aParams = $this->aEventTypes[$sEventType];
        if (isset($aParams['unique']) and $aParams['unique']) {
            /**
             * Проверяем на уникальность
             */
            if ($oEvent = $this->GetEventByTarget($sEventType, $iTargetId)) {
                /**
                 * Событие уже было
                 */
                if ($oEvent->getPublish() != $iPublish) {
                    $oEvent->setPublish($iPublish);
                    $this->UpdateEvent($oEvent);
                }

                return true;
            }
        }
        if (isset($aParams['unique_user']) and $aParams['unique_user']) {
            /**
             * Проверяем на уникальность для конкретного пользователя
             */
            if ($oEvent = $this->GetEventByTarget($sEventType, $iTargetId, $iUserId)) {
                /**
                 * Событие уже было
                 */
                if ($oEvent->getPublish() != $iPublish) {
                    $oEvent->setPublish($iPublish);
                    $this->UpdateEvent($oEvent);
                }

                return true;
            }
        }

        if ($iPublish) {
            /**
             * Создаем новое событие
             */
            $oEvent = new EntityStreamEvent();
            $oEvent->setEventType($sEventType);
            $oEvent->setUserId($iUserId);
            $oEvent->setTargetId($iTargetId);
            $oEvent->setDateAdded(date("Y-m-d H:i:s"));
            $oEvent->setPublish($iPublish);
            $this->AddEvent($oEvent);
        }

        return true;
    }

    /**
     * Чтение потока пользователя
     *
     * @param int|null $iCount  Количество
     * @param int|null $iFromId ID события с которого начинать выборку
     * @param int|null $iUserId ID пользователя
     *
     * @return array
     */
    public function Read($iCount = null, $iFromId = null, $iUserId = null)
    {
        if (!$iUserId) {
            /** @var ModuleUser $user */
            $user = LS::Make(ModuleUser::class);
            if ($user->getUserCurrent()) {
                $iUserId = $user->getUserCurrent()->getId();
            } else {
                return [];
            }
        }
        /**
         * Получаем типы событий
         */
        $aEventTypes = $this->getTypesList($iUserId);
        /**
         * Получаем список тех на кого подписан
         */
        $aUsersList = $this->getUsersList($iUserId);

        return $this->ReadEvents($aEventTypes, $aUsersList, $iCount, $iFromId);
    }

    /**
     * Чтение всей активности на сайте
     *
     * @param int|null $iCount  Количество
     * @param int|null $iFromId ID события с которого начинать выборку
     *
     * @return array
     */
    public function ReadAll($iCount = null, $iFromId = null)
    {
        /**
         * Получаем типы событий
         */
        $aEventTypes = array_keys($this->getEventTypes());

        return $this->ReadEvents($aEventTypes, null, $iCount, $iFromId);
    }

    /**
     * Чтение активности конкретного пользователя
     *
     * @param int      $iUserId ID пользователя
     * @param int|null $iCount  Количество
     * @param int|null $iFromId ID события с которого начинать выборку
     *
     * @return array
     */
    public function ReadByUserId($iUserId, $iCount = null, $iFromId = null)
    {
        /**
         * Получаем типы событий
         */
        $aEventTypes = array_keys($this->getEventTypes());
        /**
         * Получаем список тех на кого подписан
         */
        $aUsersList = [$iUserId];

        return $this->ReadEvents($aEventTypes, $aUsersList, $iCount, $iFromId);
    }

    /**
     * Количество событий конкретного пользователя
     *
     * @param int $iUserId ID пользователя
     *
     * @return int
     */
    public function GetCountByUserId($iUserId)
    {
        /**
         * Получаем типы событий
         */
        $aEventTypes = $this->getEventTypesFilter();
        if (!count($aEventTypes)) {
            return 0;
        }

        return $this->oMapper->GetCount($aEventTypes, $iUserId);
    }

    /**
     * Количество событий на которые подписан пользователь
     *
     * @param int $iUserId ID пользователя
     *
     * @return int
     */
    public function GetCountByReaderId($iUserId)
    {
        /**
         * Получаем типы событий
         */
        $aEventTypes = $this->getEventTypesFilter($this->getTypesList($iUserId));
        /**
         * Получаем список тех на кого подписан
         */
        $aUsersList = $this->getUsersList($iUserId);
        if (!count($aEventTypes)) {
            return 0;
        }

        return $this->oMapper->GetCount($aEventTypes, $aUsersList);
    }

    /**
     * Количество событий на всем сайте
     *
     * @return int
     */
    public function GetCountAll()
    {
        /**
         * Получаем типы событий
         */
        $aEventTypes = $this->getEventTypesFilter();
        if (!count($aEventTypes)) {
            return 0;
        }

        return $this->oMapper->GetCount($aEventTypes, null);
    }

    /**
     * Количество событий для пользователя
     *
     * @param array      $aEventTypes Список типов событий
     * @param array|null $aUserId     ID пользователя
     *
     * @return int
     */
    public function GetCount($aEventTypes, $aUserId = null)
    {
        return $this->oMapper->GetCount($aEventTypes, $aUserId);
    }

    /**
     * Чтение событий
     *
     * @param array      $aEventTypes Список типов событий
     * @param array|null $aUsersList  Список пользователей, чьи события читать
     * @param int        $iCount      Количество
     * @param int        $iFromId     ID события с которого начинать выборку
     *
     * @return array
     */
    public function ReadEvents($aEventTypes, $aUsersList, $iCount = null, $iFromId = null)
    {
        if (!is_null($aUsersList) and !count($aUsersList)) {
            return [];
        }
        if (!$iCount) {
            $iCount = Config::Get('module.stream.count_default');
        }

        $aEventTypes = $this->getEventTypesFilter($aEventTypes);
        if (!count($aEventTypes)) {
            return [];
        }
        /**
         * Получаем список событий
         */
        $aEvents = $this->oMapper->Read($aEventTypes, $aUsersList, $iCount, $iFromId);
        /**
         * Составляем список объектов для загрузки
         */
        $aNeedObjects = [];
        foreach ($aEvents as $oEvent) {
            if (isset($this->aEventTypes[$oEvent->getEventType()]['related'])) {
                $aNeedObjects[$this->aEventTypes[$oEvent->getEventType()]['related']][] = $oEvent->getTargetId();
            }
            $aNeedObjects['user'][] = $oEvent->getUserId();
        }
        /**
         * Получаем объекты
         */
        $aObjects = [];
        foreach ($aNeedObjects as $sType => $aListId) {
            if (count($aListId)) {
                $aListId = array_unique($aListId);
                $sMethod = 'loadRelated'.ucfirst($sType);
                if (method_exists($this, $sMethod)) {
                    if ($aRes = $this->$sMethod($aListId)) {
                        foreach ($aRes as $oObject) {
                            $aObjects[$sType][$oObject->getId()] = $oObject;
                        }
                    }
                }
            }
        }
        /**
         * Формируем результирующий поток
         */
        foreach ($aEvents as $key => $oEvent) {
            /**
             * Жестко вытаскиваем автора события
             */
            if (isset($aObjects['user'][$oEvent->getUserId()])) {
                $oEvent->setUser($aObjects['user'][$oEvent->getUserId()]);
                /**
                 * Аттачим объекты
                 */
                if (isset($this->aEventTypes[$oEvent->getEventType()]['related'])) {
                    $sTypeObject = $this->aEventTypes[$oEvent->getEventType()]['related'];
                    if (isset($aObjects[$sTypeObject][$oEvent->getTargetId()])) {
                        $oEvent->setTarget($aObjects[$sTypeObject][$oEvent->getTargetId()]);
                    } else {
                        unset($aEvents[$key]);
                    }
                } else {
                    unset($aEvents[$key]);
                }
            } else {
                unset($aEvents[$key]);
            }
        }

        return $aEvents;
    }

    /**
     * Получение типов событий, на которые подписан пользователь
     *
     * @param int $iUserId ID пользователя
     *
     * @return array
     */
    public function getTypesList($iUserId)
    {
        return $this->oMapper->getTypesList($iUserId);
    }

    /**
     * Получение списка id пользователей, на которых подписан пользователь
     *
     * @param int $iUserId ID пользователя
     *
     * @return array
     */
    protected function getUsersList($iUserId)
    {
        return $this->oMapper->getUserSubscribes($iUserId);
    }

    /**
     * Получение списка пользователей, на которых подписан пользователь
     *
     * @param int $iUserId ID пользователя
     *
     * @return array
     */
    public function getUserSubscribes($iUserId)
    {
        $aIds = $this->oMapper->getUserSubscribes($iUserId);

        return LS::Make(ModuleUser::class)->GetUsersAdditionalData($aIds);
    }

    /**
     * Проверяет подписан ли пользователь на конкретного пользователя
     *
     * @param int $iUserId       ID пользователя
     * @param int $iTargetUserId ID пользователя на которого подписан
     *
     * @return bool
     */
    public function IsSubscribe($iUserId, $iTargetUserId)
    {
        return $this->oMapper->IsSubscribe($iUserId, $iTargetUserId);
    }

    /**
     * Редактирование списка событий, на которые подписан юзер
     *
     * @param int    $iUserId ID пользователя
     * @param string $sType   Тип
     */
    public function switchUserEventType($iUserId, $sType)
    {
        if ($this->IsAllowEventType($sType)) {
            $this->oMapper->switchUserEventType($iUserId, $sType);
        }
    }

    /**
     * Переключает дефолтный список типов событий у пользователя
     *
     * @param int $iUserId ID пользователя
     */
    public function switchUserEventDefaultTypes($iUserId)
    {
        foreach ($this->aEventDefaultTypes as $sType) {
            $this->switchUserEventType($iUserId, $sType);
        }
    }

    /**
     * Подписать пользователя
     *
     * @param int $iUserId       ID пользователя
     * @param int $iTargetUserId ID пользователя на которого подписываем
     */
    public function subscribeUser($iUserId, $iTargetUserId)
    {
        $this->oMapper->subscribeUser($iUserId, $iTargetUserId);
    }

    /**
     * Отписать пользователя
     *
     * @param int $iUserId       ID пользователя
     * @param int $iTargetUserId ID пользователя на которого подписываем
     */
    public function unsubscribeUser($iUserId, $iTargetUserId)
    {
        $this->oMapper->unsubscribeUser($iUserId, $iTargetUserId);
    }

    /**
     * Получает список записей на стене
     *
     * @param array $aIds Список  ID записей на стене
     *
     * @return array
     */
    protected function loadRelatedWall($aIds)
    {
        return LS::Make(ModuleWall::class)->GetWallAdditionalData($aIds);
    }

    /**
     * Получает список топиков
     *
     * @param array $aIds Список  ID топиков
     *
     * @return array
     */
    protected function loadRelatedTopic($aIds)
    {
        return LS::Make(ModuleTopic::class)->GetTopicsAdditionalData($aIds);
    }

    /**
     * Получает список блогов
     *
     * @param array $aIds Список  ID блогов
     *
     * @return array
     */
    protected function loadRelatedBlog($aIds)
    {
        return LS::Make(ModuleBlog::class)->GetBlogsAdditionalData($aIds);
    }

    /**
     * Получает список комментариев
     *
     * @param array $aIds Список  ID комментариев
     *
     * @return array
     */
    protected function loadRelatedComment($aIds)
    {
        return LS::Make(ModuleComment::class)->GetCommentsAdditionalData($aIds);

    }

    /**
     * Получает список пользователей
     *
     * @param array $aIds Список  ID пользователей
     *
     * @return array
     */
    protected function loadRelatedUser($aIds)
    {
        return LS::Make(ModuleUser::class)->GetUsersAdditionalData($aIds);
    }
}
