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

namespace App\Actions;

use App\Entities\EntityUser;
use App\Modules\ModuleAPI;
use App\Modules\ModuleCrypto;
use App\Modules\ModuleUser;
use Engine\LS;
use Engine\Modules\ModuleSecurity;
use Engine\Result\View\JsonView;
use Engine\Routing\Controller;

class ActionApi extends Controller
{
    /**
     * Текущий пользователь
     *
     * @var EntityUser|null
     */
    protected $oUserCurrent = null;

    /**
     * @param \App\Modules\ModuleUser   $user
     * @param \App\Modules\ModuleAPI    $api
     * @param \App\Modules\ModuleCrypto $crypto
     *
     * @return \Engine\Result\View\JsonView
     */
    protected function eventAjaxLogin(ModuleUser $user, ModuleAPI $api, ModuleCrypto $crypto): JsonView
    {
        //Проверяем тип запроса. Если не POST - возвращаем ошибку 400
        if ($_SERVER['REQUEST_METHOD'] != "POST") {
            return JsonView::from(['message' => 'Bad request']);
        }

        if ((getRequest('login') and getRequest('password')) or getRequest('device_uid')) {
            //Если логин и пароль не заданы, то пробуем залогинить по device_uid
            if (!getRequest('login') and !getRequest('password') and getRequest('device_uid')) {
                if ($oUser = $user->GetUserById($api->getUserByKey(getRequest('device_uid')))) {
                    if ($user->Authorization($oUser, true)) {
                        return JsonView::from(['notice' => 'Authenfication complete!']);
                    }
                } else {
                    return JsonView::from(['message' => 'Device UID incorrect']);
                }
            }

            if (!is_string(getRequest('login')) or !is_string(getRequest('password'))) {
                return JsonView::from(['message' => "Login or password isn't a string"]);
            }
            /**
             * Проверяем есть ли такой юзер по логину
             */
            if ((func_check(getRequest('login'), 'mail') and $oUser = $user->GetUserByMail(getRequest('login'))) or $oUser = $user->GetUserByLogin(getRequest('login'))) {
                /**
                 * Сверяем хеши паролей и проверяем активен ли юзер
                 */

                //TODO: DRY IT.

                /**
                 * Проверяем пароль и обновляем хеш, если нужно
                 */
                $user_password = $oUser->getPassword();
                if ($crypto->PasswordVerify(getRequest('password'), $user_password)) {
                    if ($crypto->PasswordNeedsRehash($user_password)) {
                        $oUser->setPassword($crypto->PasswordHash(getRequest('password')));
                        $user->Update($oUser);
                    }

                    /**
                     * Проверяем активен ли юзер
                     */
                    if (!$oUser->getActivate()) {
                        return JsonView::from(['message' => "Login or password isn't a string"]);
                    }
                    /**
                     * Авторизуем
                     */
                    if ($user->Authorization($oUser, true)) {
                        $view = JsonView::empty();
                        if (getRequest('device_uid')) {
                            if ($sKey = $api->setKey($oUser->getId(), getRequest('device_uid'))) {
                                $view->with(['newKey' => $sKey]);
                            }
                        }
                        return $view->with([
                            'notice' => "Authenfication complete!",
                            'ls_key' => LS::Make(ModuleSecurity::class)->GenerateSessionKey()
                        ]);
                    } else {
                        return JsonView::from(['message' => 'Authenfication faild']);
                    }
                } else {
                    return JsonView::from(['message' => 'Wrong password!']);
                }
            } else {
                return JsonView::from(['message' => 'User not exists!']);
            }
        } else {
            return JsonView::from(['message' => 'Bad request']);
        }
    }
}
