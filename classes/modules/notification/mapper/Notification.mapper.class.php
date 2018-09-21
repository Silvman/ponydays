<?php
/**
 * Created by PhpStorm.
 * User: frumscepend
 * Date: 7/30/18
 * Time: 1:53 AM
 */

/**
 * Маппер уведомлений, работа с базой данных
 *
 * @package modules.notification
 */

class ModuleNotification_MapperNotification extends Mapper {

	/**
	 * Получение всех уведомлений пользователя с пагинацией
	 * @param $userId int ID пользователя
	 * @param $page int номер страницы
	 * @param $count int количество на странице
	 * @return array уведомления
	 * @throws Exception
	 */

	public function getNotification($userId, $page, $count){
		$sql = "SELECT
				*
				FROM
				".Config::Get('db.table.notification')."
				WHERE
				user_id = ?
				LIMIT ? 
				OFFSET ?
		";
		$aNotifications=array();
		if ($aRows=$this->oDb->select($sql,$userId, $count, (($page-1)*$count))) {
			foreach ($aRows as $aRow) {
				$aNotifications[]=Engine::GetEntity('Notification',$aRow);
			}
		}
		return $aNotifications;
	}

	/**
	 * Получение уведомления по id
	 * @param $notificationId int ID уведомления
	 * @return array уведомления
	 * @throws Exception
	 */

	public function getNotificationById($notificationId){
		$sql = "SELECT
				*
				FROM
				".Config::Get('db.table.notification')."
				WHERE
				notification_id = ?
		";
		$aNotifications=array();
		if ($aRows=$this->oDb->select($sql, $notificationId)) {
			foreach ($aRows as $aRow) {
				$aNotifications[]=Engine::GetEntity('Notification',$aRow);
			}
		}
		return $aNotifications[0];
	}

	/**
	 * Создание нового уведомления
	 *
	 * @param ModuleNotification_EntityNotification $eNotification
	 * @return bool|int
	 */
	public function createNotification(ModuleNotification_EntityNotification $eNotification){
		$sql = "INSERT INTO ".Config::Get('db.table.notification')."
				(user_id,
				date,
				text,
				title,
				link,
				rating,
				notification_type,
				target_type,
				target_id)
				VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
		";
		if ($iId=$this->oDb->query($sql,$eNotification->getUserId(), $eNotification->getDate(), $eNotification->getText(),
			$eNotification->getTitle(), $eNotification->getLink(), $eNotification->getRating(), $eNotification->getType(),
			$eNotification->getTargetType(), $eNotification->getTargetId())) {
        	return $iId;
		}
		return false;
	}

	/**
	 * Удаление уведомления
	 *
	 * @param $notificationId
	 * @return bool
	 */
	public function deleteNotification($notificationId) {
		$sql = "DELETE FROM ".Config::Get('db.table.notification')." 
				WHERE notification_id = ?d";
		if ($this->oDb->query($sql,$notificationId)) {
			return true;
		}
		return false;
	}

	/**
	 * Удаление всех уведомлений пользователя
	 * @param $userId
	 * @return bool
	 */
	public function deleteAllNotifications($userId) {
		$sql = "DELETE FROM ".Config::Get('db.table.notification')." 
				WHERE user_id = ?d";
		if ($this->oDb->query($sql,$userId)) {
			return true;
		}
		return false;
	}

	//todo: удаление по массиву id, удаление по типу

}
?>