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

use App\Entities\EntityUser;
use App\Modules\ModuleUser;
use DbSimple_Mysql;
use Engine\Modules\ModuleCache;
use Engine\Modules\ModuleDatabase;
use Engine\Modules\ModuleHook;
use ReflectionFunction;

set_include_path(get_include_path() . PATH_SEPARATOR . dirname(__FILE__));

/**
 * Основной класс движка. Ядро.
 *
 * Производит инициализацию плагинов, модулей, хуков.
 * Через этот класс происходит выполнение методов всех модулей, которые вызываются как <pre>$this->Module_Method();</pre>
 * Также отвечает за автозагрузку остальных классов движка.
 *
 * В произвольном месте (не в классах движка у которых нет обработки метода __call() на выполнение модулей) метод модуля можно вызвать так:
 * <pre>
 * Engine::getInstance()->Module_Method();
 * </pre>
 *
 * @package engine
 * @since 1.0
 */
class Engine extends LsObject {

	/**
	 * Имя плагина
	 * @var int
	 */
	//const CI_PLUGIN = 1;

	/**
	 * Имя экшна
	 * @var int
	 */
	const CI_ACTION = 2;

	/**
	 * Имя модуля
	 * @var int
	 */
	const CI_MODULE = 4;

	/**
	 * Имя сущности
	 * @var int
	 */
	const CI_ENTITY = 8;

	/**
	 * Имя маппера
	 * @var int
	 */
	const CI_MAPPER = 16;

	/**
	 * Имя метода
	 * @var int
	 */
	const CI_METHOD = 32;

	/**
	 * Имя хука
	 * @var int
	 */
	const CI_HOOK = 64;

	/**
	 * Имя класс наследования
	 * @var int
	 */
	const CI_INHERIT = 128;

	/**
	 * Имя блока
	 * @var int
	 */
	const CI_BLOCK = 256;

	/**
	 * Префикс плагина
	 * @var int
	 */
	const CI_PPREFIX = 8192;

	/**
	 * Разобранный класс наследования
	 * @var int
	 */
	const CI_INHERITS = 16384;

	/**
	 * Путь к файлу класса
	 * @var int
	 */
	const CI_CLASSPATH = 32768;

	/**
	 * Все свойства класса
	 * @var int
	 */
	const CI_ALL = 65535;

	/**
	 * Свойства по-умолчанию
	 * CI_ALL ^ (CI_CLASSPATH | CI_INHERITS | CI_PPREFIX)
	 * @var int
	 */
	const CI_DEFAULT = 8191;

	/**
	 * Объекты
	 * CI_ACTION | CI_MAPPER | CI_HOOK | CI_PLUGIN | CI_ACTION | CI_MODULE | CI_ENTITY | CI_BLOCK
	 * @var int
	 */
	const CI_OBJECT = 350;

	/**
	 * Текущий экземпляр движка, используется для синглтона.
	 * @see getInstance использование синглтона
	 *
	 * @var Engine
	 */
	static protected $oInstance=null;
	/**
	 * Список загруженных модулей
	 *
	 * @var array
	 */
	protected $aModules=array();
	/**
	 * Содержит конфиг модулей.
	 * Используется для получания списка модулей для авто-загрузки. Остальные модули загружаются при первом обращении.
	 * В конфиге определен так:
	 * <pre>
	 * $config['module']['autoLoad'] = array('Hook','Cache','Security','Session','Lang','Message','User');
	 * </pre>
	 *
	 * @var array
	 */
	protected $aConfigModule;
	/**
	 * Время загрузки модулей в микросекундах
	 *
	 * @var int
	 */
	public $iTimeLoadModule=0;
	/**
	 * Текущее время в микросекундах на момент инициализации ядра(движка).
	 * Определается так:
	 * <pre>
	 * $this->iTimeInit=microtime(true);
	 * </pre>
	 *
	 * @var int|null
	 */
	protected $iTimeInit=null;


	/**
	 * Вызывается при создании объекта ядра.
	 * Устанавливает время старта инициализации и обрабатывает входные параметры PHP
	 *
	 */
	protected function __construct() {
		$this->iTimeInit=microtime(true);
		if (get_magic_quotes_gpc()) {
			func_stripslashes($_REQUEST);
			func_stripslashes($_GET);
			func_stripslashes($_POST);
			func_stripslashes($_COOKIE);
		}
	}

	/**
	 * Ограничиваем объект только одним экземпляром.
	 * Функционал синглтона.
	 *
	 * Используется так:
	 * <pre>
	 * Engine::getInstance()->Module_Method();
	 * </pre>
	 *
	 * @return Engine
	 */
	static public function getInstance() {
		if (isset(self::$oInstance) and (self::$oInstance instanceof self)) {
			return self::$oInstance;
		} else {
			self::$oInstance= new self();
			return self::$oInstance;
		}
	}

	/**
	 * Инициализация ядра движка
	 *
	 */
	public function Init() {
		/**
		 * Инициализируем хуки
		 */
		$this->InitHooks();
		/**
		 * Загружаем модули автозагрузки
		 */
		$this->LoadModules();
		/**
		 * Запускаем хуки для события завершения инициализации Engine
		 */
		/** @var \Engine\Modules\ModuleHook $hook */
		$hook = $this->make(ModuleHook::class);
		$hook->Run('engine_init_complete');
	}
	/**
	 * Завершение работы движка
	 * Завершает все модули.
	 *
	 */
	public function Shutdown() {
		$this->ShutdownModules();
	}

	/**
	 * Инициализирует модуль
	 *
	 * @param Module $oModule	Объект модуля
	 */
	protected function InitModule($oModule){
		$oModule->Init();
		$oModule->SetInit();
	}

	/**
	 * Проверяет модуль на инициализацию
	 *
	 * @param string $sModuleClass	Класс модуля
	 * @return bool
	 */
	public function isInitModule($sModuleClass) {
		if(isset($this->aModules[$sModuleClass]) and $this->aModules[$sModuleClass]->isInit()){
			return true;
		}
		return false;
	}

	/**
	 * Завершаем работу всех модулей
	 *
	 */
	protected function ShutdownModules() {
		foreach ($this->aModules as $sKey => $oModule) {
			/**
			 * Замеряем время shutdown`a модуля
			 */
			$oProfiler=ProfilerSimple::getInstance();
			$iTimeId=$oProfiler->Start('ShutdownModule',get_class($oModule));

			$oModule->Shutdown();

			$oProfiler->Stop($iTimeId);
		}
	}

	/**
	 * Выполняет загрузку модуля по его названию
	 *
	 * @param  string $sModuleClass	Класс модуля
	 * @param  bool $bInit Инициализировать модуль или нет
	 * @deprecated Будет уничтожено в дальнейшем. Используйте make(Module::class)
	 * @throws \RuntimeException если класс $sModuleClass не существует
	 *
	 * @return Module
	 */
	public function LoadModule($sModuleClass,$bInit=false) {
		if (!class_exists($sModuleClass))
		{
			throw new \RuntimeException(sprintf('Class "%s" not found!', $sModuleClass));
		}
		/**
		 * Создаем объект модуля
		 */
		$oModule=new $sModuleClass($this);
		$this->aModules[$sModuleClass]=$oModule;
		if ($bInit or $sModuleClass=='ModuleCache') {
			$this->InitModule($oModule);
		}
		return $oModule;
	}

	/**
	 * Загружает модули из авто-загрузки и передает им в конструктор ядро
	 *
	 */
	protected function LoadModules() {
		foreach (Config::Get('module.autoload') as $sModuleClass) {
		    $this->make($sModuleClass);
		}
    }
	/**
	 * Выполняет загрузку конфигов
	 *
	 */
	protected function LoadConfig() {
		$this->aConfigModule = Config::Get('module');
	}
	/**
	 * Регистрирует хуки из /classes/hooks/
	 *
	 */
	protected function InitHooks() {
		$hookList = Config::Get('sys.hooks');

		foreach ($hookList as $hook) {
		    /** @var Hook $oHook */
		    $oHook = new $hook();
		    $oHook->RegisterHook();
        }
	}
	/**
	 * Проверяет файл на существование, если используется кеширование memcache то кеширует результат работы
	 *
	 * @param  string $sFile	Полный путь до файла
	 * @param  int $iTime	Время жизни кеша
	 * @return bool
	 */
	public function isFileExists($sFile,$iTime=3600) {
		//FIXME: пока так
		return file_exists($sFile);

		if(
			!$this->isInit('cache')
			|| !Config::Get('sys.cache.use')
			|| Config::Get('sys.cache.type') != 'memory'
		){
			return file_exists($sFile);
		}

		/** @var ModuleCache $cache */
		$cache = $this->make(ModuleCache::class);
		if (false === ($data = $cache->Get("file_exists_{$sFile}"))) {
			$data=file_exists($sFile);
			$cache->Set((int)$data, "file_exists_{$sFile}", array(), $iTime);
		}
		return $data;
	}

	/**
	 * Возвращает статистику выполнения
	 *
	 * @return array
	 */
	public function getStats() {
	    /** @var ModuleDatabase $db */
	    $db = LS::Make(ModuleDatabase::class);
	    /** @var \Engine\Modules\ModuleCache $cache */
	    $cache = LS::Make(ModuleCache::class);
		return array(
		    'sql' => $db->GetStats(),
            'cache' => $cache->GetStats(),
            'engine' => array('time_load_module' => round($this->iTimeLoadModule,3))
        );
	}

	/**
	 * Возвращает время старта выполнения движка в микросекундах
	 *
	 * @return int
	 */
	public function GetTimeInit() {
		return $this->iTimeInit;
	}

	/**
	 * Блокируем копирование/клонирование объекта ядра
	 *
	 */
	protected function __clone() {}

	public static function MakeMapper($class, $connect=null) {
        if (!class_exists($class)) {
            throw new \RuntimeException(sprintf('Class "%s" not found!', $class));
        }
        if(!$connect) {
            /** @var ModuleDatabase $db */
            $db = LS::Make(ModuleDatabase::class);
            $connect = $db->GetConnect();
        }
        return new $class($connect);
    }

	public function make(string $class): Module {
		if(isset($this->aModules[$class])) {
			return $this->aModules[$class];
		} else {
			if (!class_exists($class)) {
				throw new \RuntimeException(sprintf('Class "%s" not found!', $class));
			}
			$module = new $class($this);
			$this->aModules[$class] = $module;
			$this->InitModule($module);
			return $module;
		}
	}

    /**
     * @param callable $func
     */
    public function order(callable $func) {
        try {
            $refl = new ReflectionFunction($func);
            $args = array();
            foreach ($refl->getParameters() as $par) {
                $class = $par->getClass();
                $args[] = $this->make($class->getName());
            }
            $refl->invokeArgs($args);
        } catch (\ReflectionException $e) {
            throw new \RuntimeException('Invalid order call');
        }
    }
}

/**
 * Короткий алиас для вызова основных методов движка
 * @package engine
 * @since 1.0
 */
class LS extends LsObject
{

    /**
     * Возвращает ядро
     *
     * @see Engine::GetInstance
     *
     * @return Engine
     */
    static public function E()
    {
        return Engine::GetInstance();
    }

    /**
     * Возвращает объект маппера
     *
     * @see Engine::MakeMapper
     *
     * @param string              $sClassName Класс модуля маппера
     * @param DbSimple_Mysql|null $oConnect   Объект коннекта к БД
     *
     * @return mixed
     */
    static public function Mpr($sClassName, $oConnect = null)
    {
        return Engine::MakeMapper($sClassName, $oConnect);
    }

    /**
     * Возвращает текущего авторизованного пользователя
     *
     * @see ModuleUser::GetUserCurrent
     *
     * @return \App\Modules\User\\App\Entities\EntityUser
     */
    static public function CurUsr()
    {
        return self::Make(ModuleUser::class)->GetUserCurrent();
    }

    /**
     * Возвращает true если текущий пользователь администратор
     *
     * @see ModuleUser::GetUserCurrent
     * @see \App\Entities\EntityUser::isAdministrator
     *
     * @return bool
     */
    static public function Adm()
    {
        return self::CurUsr() && self::CurUsr()->isAdministrator();
    }

    static public function Make(string $class): Module
    {
        return self::E()->make($class);
    }

    static public function Order(callable $func)
    {
        self::E()->order($func);
    }
}