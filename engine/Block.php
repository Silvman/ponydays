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

namespace Engine;

/**
 * Абстрактный класс блока
 * Это те блоки которые обрабатывают шаблоны Smarty перед выводом(например блок "Облако тегов")
 *
 * @package engine
 * @since   1.0
 */
abstract class Block extends LsObject
{
    /**
     * Ядро движка
     *
     * @var Engine|null
     */
    protected $oEngine = null;
    /**
     * Список параметров блока
     *
     * @var array
     */
    protected $aParams = [];

    /**
     * При создании блока передаем в него параметры
     *
     * @param array $aParams Список параметров блока
     */
    public function __construct($aParams)
    {
        $this->aParams = $aParams;
        $this->oEngine = Engine::getInstance();
    }

    /**
     * Возвращает параметр по имени
     *
     * @param string     $sName Имя параметра
     * @param null|mixed $def   Дефолтное значение параметра, возвращается если такого параметра нет
     *
     * @return mixed
     */
    protected function GetParam($sName, $def = null)
    {
        if (isset($this->aParams[$sName])) {
            return $this->aParams[$sName];
        } else {
            return $def;
        }
    }

    /**
     * Метод запуска обработки блока.
     * Его необходимо определять в конкретном блоге.
     *
     * @abstract
     */
    abstract public function Exec();
}
