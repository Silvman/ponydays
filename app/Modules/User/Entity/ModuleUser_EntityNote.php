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

namespace App\Modules\User\Entity;

use App\Modules\User\ModuleUser;
use Engine\Config;
use Engine\Entity;
use Engine\LS;
use Engine\Modules\Lang\ModuleLang;

/**
 * Сущность заметки о пользователе
 *
 * @package modules.user
 * @since 1.0
 */
class ModuleUser_EntityNote extends Entity {
	/**
	 * Определяем правила валидации
	 *
	 * @var array
	 */
	protected $aValidateRules=array(
		array('target_user_id','target'),
	);

	/**
	 * Инициализация
	 */
	public function Init() {
		parent::Init();
		$this->aValidateRules[]=array('text','string','max'=>Config::Get('module.user.usernote_text_max'),'min'=>1,'allowEmpty'=>false);
	}
	/**
	 * Валидация пользователя
	 *
	 * @param string $sValue	Значение
	 * @param array $aParams	Параметры
	 * @return bool
	 */
	public function ValidateTarget($sValue,$aParams) {
		if ($oUserTarget=LS::Make(ModuleUser::class)->GetUserById($sValue) and $this->getUserId()!=$oUserTarget->getId()) {
			return true;
		}
		return LS::Make(ModuleLang::class)->Get('user_note_target_error');
	}
}