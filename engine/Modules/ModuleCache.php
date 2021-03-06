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

namespace Engine\Modules;

use Dklab_Cache_Backend_MemcachedMultiload;
use Dklab_Cache_Backend_Profiler;
use Dklab_Cache_Backend_TagEmuWrapper;
use Engine\Config;
use Engine\Module;
use Zend_Cache;
use Zend_Cache_Backend;
use Zend_Cache_Backend_File;
use Zend_Cache_Backend_Xcache;

require_once('./lib/DklabCache/config.php');
require_once(LS_DKCACHE_PATH.'Zend/Cache.php');
require_once(LS_DKCACHE_PATH.'Cache/Backend/MemcachedMultiload.php');
require_once(LS_DKCACHE_PATH.'Cache/Backend/TagEmuWrapper.php');
require_once(LS_DKCACHE_PATH.'Cache/Backend/Profiler.php');

/**
 * Типы кеширования: file и memory
 *
 */
define('SYS_CACHE_TYPE_FILE', 'file');
define('SYS_CACHE_TYPE_MEMORY', 'memory');
define('SYS_CACHE_TYPE_XCACHE', 'xcache');

/**
 * Модуль кеширования.
 * Для реализации кеширования используетс библиотека Zend_Cache с бэкэндами File, Memcache и XCache.
 * Т.к. в memcache нет встроенной поддержки тегирования при кешировании, то для реализации тегов используется враппер
 * от Дмитрия Котерова - Dklab_Cache_Backend_TagEmuWrapper.
 *
 * Пример использования:
 * <pre>
 *    // Получает пользователя по его логину
 *    public function GetUserByLogin($sLogin) {
 *        // Пытаемся получить значение из кеша
 *        if (false === ($oUser = LS::Make(ModuleCache::class)->Get("user_login_{$sLogin}"))) {
 *            // Если значение из кеша получить не удалось, то обращаемся к базе данных
 *            $oUser = $this->oMapper->GetUserByLogin($sLogin);
 *            // Записываем значение в кеш
 *            LS::Make(ModuleCache::class)->Set($oUser, "user_login_{$sLogin}", array(), 60*60*24*5);
 *        }
 *        return $oUser;
 *    }
 *
 *    // Обновляет пользовател в БД
 *    public function UpdateUser($oUser) {
 *        // Удаляем кеш конкретного пользователя
 *        LS::Make(ModuleCache::class)->Delete("user_login_{$oUser->getLogin()}");
 *        // Удалем кеш со списком всех пользователей
 *        LS::Make(ModuleCache::class)->Clean(Zend_Cache::CLEANING_MODE_MATCHING_TAG,array('user_update'));
 *        // Обновлем пользовател в базе данных
 *        return $this->oMapper->UpdateUser($oUser);
 *    }
 *
 *    // Получает список всех пользователей
 *    public function GetUsers() {
 *        // Пытаемся получить значение из кеша
 *        if (false === ($aUserList = LS::Make(ModuleCache::class)->Get("users"))) {
 *            // Если значение из кеша получить не удалось, то обращаемся к базе данных
 *            $aUserList = $this->oMapper->GetUsers();
 *            // Записываем значение в кеш
 *            LS::Make(ModuleCache::class)->Set($aUserList, "users", array('user_update'), 60*60*24*5);
 *        }
 *        return $aUserList;
 *    }
 * </pre>
 *
 * @package engine.modules
 * @since   1.0
 */
class ModuleCache extends Module
{
    /**
     * Объект бэкенда кеширования
     *
     * @var Zend_Cache_Backend
     */
    protected $oBackendCache = null;
    /**
     * Используется кеширование или нет
     *
     * @var bool
     */
    protected $bUseCache;
    /**
     * Тип кеширования, прописан в глобльном конфиге config.php
     *
     * @var string
     */
    protected $sCacheType;
    /**
     * Статистика кеширования
     *
     * @var array
     */
    protected $aStats = [
        'time'      => 0,
        'count'     => 0,
        'count_get' => 0,
        'count_set' => 0,
    ];
    /**
     * Хранилище для кеша на время сессии
     *
     * @see SetLife
     * @see GetLife
     *
     * @var array
     */
    protected $aStoreLife = [];
    /**
     * Префикс для "умного" кеширования
     *
     * @see SmartSet
     * @see SmartGet
     *
     * @var string
     */
    protected $sPrefixSmartCache = 'for-smart-cache-';

    /**
     * Инициализируем нужный тип кеша
     *
     * @throws \Zend_Cache_Exception
     * @throws \Exception
     */
    public function Init()
    {
        $this->bUseCache = Config::Get('sys.cache.use');
        $this->sCacheType = Config::Get('sys.cache.type');

        if (!$this->bUseCache) {
            return;
        }
        /**
         * Файловый кеш
         */
        if ($this->sCacheType == SYS_CACHE_TYPE_FILE) {
            require_once(LS_DKCACHE_PATH.'Zend/Cache/Backend/File.php');
            $oCahe = new Zend_Cache_Backend_File(
                [
                    'cache_dir'              => Config::Get('sys.cache.dir'),
                    'file_name_prefix'       => Config::Get('sys.cache.prefix'),
                    'read_control_type'      => 'crc32',
                    'hashed_directory_level' => Config::Get('sys.cache.directory_level'),
                    'read_control'           => true,
                    'file_locking'           => true,
                ]
            );
            $this->oBackendCache = new Dklab_Cache_Backend_Profiler($oCahe, [$this, 'CalcStats']);
            /**
             * Кеш на основе Memcached
             */
        } elseif ($this->sCacheType == SYS_CACHE_TYPE_MEMORY) {
            require_once(LS_DKCACHE_PATH.'Zend/Cache/Backend/Memcached.php');
            $aConfigMem = Config::Get('memcache');

            $oCahe = new Dklab_Cache_Backend_MemcachedMultiload($aConfigMem);
            $this->oBackendCache =
                new Dklab_Cache_Backend_TagEmuWrapper(new Dklab_Cache_Backend_Profiler($oCahe, [$this, 'CalcStats']));
            /**
             * Кеш на основе XCache
             */
        } elseif ($this->sCacheType == SYS_CACHE_TYPE_XCACHE) {
            require_once(LS_DKCACHE_PATH.'Zend/Cache/Backend/Xcache.php');
            $aConfigMem = Config::Get('xcache');

            $oCahe = new Zend_Cache_Backend_Xcache(is_array($aConfigMem) ? $aConfigMem : []);
            $this->oBackendCache =
                new Dklab_Cache_Backend_TagEmuWrapper(new Dklab_Cache_Backend_Profiler($oCahe, [$this, 'CalcStats']));
        } else {
            throw new \Exception("Wrong type of caching: ".$this->sCacheType." (file, memory, xcache)");
        }
        /**
         * Дабы не засорять место протухшим кешем, удаляем его в случайном порядке, например 1 из 50 раз
         */
        if (rand(1, 50) == 33) {
            $this->Clean(Zend_Cache::CLEANING_MODE_OLD);
        }
    }

    /**
     * Получить значение из кеша
     *
     * @param string $sName Имя ключа
     *
     * @return mixed|bool
     */
    public function Get($sName)
    {
        if (!$this->bUseCache) {
            return false;
        }
        /**
         * Т.к. название кеша может быть любым то предварительно хешируем имя кеша
         */
        if (!is_array($sName)) {
            $sName = md5(Config::Get('sys.cache.prefix').$sName);
            $data = $this->oBackendCache->load($sName);
            if ($this->sCacheType == SYS_CACHE_TYPE_FILE and $data !== false) {
                return unserialize($data);
            } else {
                return $data;
            }
        } else {
            return $this->multiGet($sName);
        }
    }

    /**
     * Получения значения из "умного" кеша для борьбы с конкурирующими запросами
     * Если кеш "протух", и за ним обращаются много запросов, то только первый запрос вернет FALSE, остальные будут
     * получать чуть устаревшие данные из временного кеша, пока их не обновит первый запрос
     *
     * @param string $sName Имя ключа
     *
     * @return bool|mixed
     */
    public function SmartGet($sName)
    {
        if (!$this->bUseCache) {
            return false;
        }
        /**
         * Если данных в основном кеше нет, то перекладываем их из временного
         */
        if (($data = $this->Get($sName)) === false) {
            $this->Set(
                $this->Get($this->sPrefixSmartCache.$sName),
                $sName,
                [],
                60
            ); // храним данные из временного в основном не долго
        }

        return $data;
    }

    /**
     * Поддержка мульти-запросов к кешу
     * Такие запросы поддерживает только memcached, поэтому для остальных типов делаем эмуляцию
     *
     * @param  array $aName Имя ключа
     *
     * @return bool|array
     */
    public function multiGet($aName)
    {
        if (count($aName) == 0) {
            return false;
        }
        if ($this->sCacheType == SYS_CACHE_TYPE_MEMORY) {
            $aKeys = [];
            $aKv = [];
            foreach ($aName as $sName) {
                $aKeys[] = md5(Config::Get('sys.cache.prefix').$sName);
                $aKv[md5(Config::Get('sys.cache.prefix').$sName)] = $sName;
            }
            $data = $this->oBackendCache->load($aKeys);
            if ($data and is_array($data)) {
                $aData = [];
                foreach ($data as $key => $value) {
                    $aData[$aKv[$key]] = $value;
                }
                if (count($aData) > 0) {
                    return $aData;
                }
            }

            return false;
        } else {
            $aData = [];
            foreach ($aName as $key => $sName) {
                if ((false !== ($data = $this->Get($sName)))) {
                    $aData[$sName] = $data;
                }
            }
            if (count($aData) > 0) {
                return $aData;
            }

            return false;
        }
    }

    /**
     * Записать значение в кеш
     *
     * @param  mixed  $data      Данные для хранения в кеше
     * @param  string $sName     Имя ключа
     * @param  array  $aTags     Список тегов, для возможности удалять сразу несколько кешей по тегу
     * @param  int    $iTimeLife Время жизни кеша в секундах
     *
     * @return bool
     */
    public function Set($data, $sName, $aTags = [], $iTimeLife = false)
    {
        if (!$this->bUseCache) {
            return false;
        }
        /**
         * Т.к. название кеша может быть любым то предварительно хешируем имя кеша
         */
        $sName = md5(Config::Get('sys.cache.prefix').$sName);
        if ($this->sCacheType == SYS_CACHE_TYPE_FILE) {
            $data = serialize($data);
        }

        return $this->oBackendCache->save($data, $sName, $aTags, $iTimeLife);
    }

    /**
     * Устанавливаем значение в "умном" кеша для борьбы с конкурирующими запросами
     * Дополнительно сохраняет значение во временном кеше на чуть большее время
     *
     * @param mixed  $data      Данные для хранения в кеше
     * @param string $sName     Имя ключа
     * @param array  $aTags     Список тегов, для возможности удалять сразу несколько кешей по тегу
     * @param int    $iTimeLife Время жизни кеша в секундах
     *
     * @return bool
     */
    public function SmartSet($data, $sName, $aTags = [], $iTimeLife = false)
    {
        $this->Set($data, $this->sPrefixSmartCache.$sName, [], $iTimeLife !== false ? $iTimeLife + 60 : false);

        return $this->Set($data, $sName, $aTags, $iTimeLife);
    }

    /**
     * Удаляет значение из кеша по ключу(имени)
     *
     * @param string $sName Имя ключа
     *
     * @return bool
     */
    public function Delete($sName)
    {
        if (!$this->bUseCache) {
            return false;
        }
        /**
         * Т.к. название кеша может быть любым то предварительно хешируем имя кеша
         */
        $sName = md5(Config::Get('sys.cache.prefix').$sName);

        return $this->oBackendCache->remove($sName);
    }

    /**
     * Чистит кеши
     *
     * @param int   $cMode Режим очистки кеша
     * @param array $aTags Список тегов, актуально для режима Zend_Cache::CLEANING_MODE_MATCHING_TAG
     *
     * @return bool
     */
    public function Clean($cMode = Zend_Cache::CLEANING_MODE_ALL, $aTags = [])
    {
        if (!$this->bUseCache) {
            return false;
        }

        return $this->oBackendCache->clean($cMode, $aTags);
    }

    /**
     * Подсчет статистики использования кеша
     *
     * @param int    $iTime   Время выполнения метода
     * @param string $sMethod имя метода
     */
    public function CalcStats($iTime, $sMethod)
    {
        $this->aStats['time'] += $iTime;
        $this->aStats['count']++;
        if ($sMethod == 'Dklab_Cache_Backend_Profiler::load') {
            $this->aStats['count_get']++;
        }
        if ($sMethod == 'Dklab_Cache_Backend_Profiler::save') {
            $this->aStats['count_set']++;
        }
    }

    /**
     * Возвращает статистику использования кеша
     *
     * @return array
     */
    public function GetStats()
    {
        return $this->aStats;
    }

    /**
     * Сохраняет значение в кеше на время исполнения скрипта(сессии), некий аналог Registry
     *
     * @param mixed  $data  Данные для сохранения в кеше
     * @param string $sName Имя ключа
     */
    public function SetLife($data, $sName)
    {
        $this->aStoreLife[$sName] = $data;
    }

    /**
     * Получает значение из текущего кеша сессии
     *
     * @param string $sName Имя ключа
     *
     * @return mixed
     */
    public function GetLife($sName)
    {
        if (key_exists($sName, $this->aStoreLife)) {
            return $this->aStoreLife[$sName];
        }

        return false;
    }
}
