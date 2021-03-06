<?php
    namespace Lacodda\BxComponent;

    use Bitrix\Main\Application;
    use Bitrix\Main\NotImplementedException;
    use Bitrix\Main\Localization\Loc;
    use Lacodda\BxModule\Helper\Lang;

    /**
     * Class BaseComponent
     *
     * @package Lacodda\BxComponent
     *
     * Базовый компонент, предназначенный для простой реализации MVC. Основные функции:
     * <ul>
     * <li>Обработка ЧПУ "из коробки" (при условии, что параметры компонента правильно настроены при подключении)</li>
     * <li>Обработка входящих запросов в MVC-стиле с применением кеширования по-умолчанию</li>
     * <li>Обработка и кеширование AJAX в коде компонента</li>
     * <li>Обработка ошибок, перехват исключений</li>
     * <li>Возможность наследования</li>
     * <li>Легковесность, для начала работы достаточно знать API битрикс и прочитать докуменацию к функциям класса</li>
     * </ul>
     *
     * Компонент обрабатывает дополнительные параметры:
     * <ul>
     * <li>AJAX_CHECK_SESSID - Проверяет sessid при AJAX запросах.
     * Отправка сгенерированного sessid остаётся на совести программиста, поэтому по-умолчанию данная функция выключена.
     * К тому же на клиенте есть неприятности в IE8
     * </li>
     * <li>VERBOSE - Подробный вывод данных исключений. Отражается только на AJAX запросах.
     * По-умолчанию выключен, т.к. в production-режиме пользователю ни к чему знать, в какой строке какого кода было выброшено исключение</li>
     * <li>CACHE_ACTION - массив с описанием правил кеширования экшенов контроллера. Ключ - имя контроллера.
     * Значение: Y - если кешировать, N - не кешировть, любая другая строка, которая заполнит $additionalCacheId.
     * Можно так же передать функцию, в качестве аргумента принимающую экземпляр класса компонента, возвращающую строку для $additionalCacheId.</li>
     * <li>ACTION_CLASS - массив для указания, для каких actions использовать отдельный класс.
     * Ключ массива - название actionв нижнем регистре. Значение - полное имя класса. Классом, отрабатывающим действие контроллера,
     * может быть любой класс с публичной не статичной функцией run()</li>
     * <li>GREEDY_PARTS - "жадные" участки шаблона URL, т.е. содержащие слеши. Нужно указать список плейсхолдеров через запятую. </li>
     * <li>LANG_LAZY_LOAD - Использовать или нет "ленивую" загрузку lang-файлов. Если выключено (по-умолчанию), то становится возможным
     * использовать наследование lang-файлов, даже если у класса много родителей</li>
     * </ul>
     *
     * Объявив константу BX_DEBUG, можно управлять режимом отладки компонента.
     */
    class BaseComponent
        extends \CBitrixComponent
    {
        /**
         * @var Lang
         */
        protected $lang;

        /**
         * 404 ошибка, маршрут не найден
         *
         * @see BaseComponent::showError()
         */
        const ERR_404 = 0;

        /**
         * Ошибка произошлав результате выброшенного исключения где-то в коде
         *
         * @see BaseComponent::showError()
         */
        const ERR_EXCEPTION = 1;

        /**
         * Флаг пропуска исполнения AJAX.
         * Используется, если необходимо прервать обработку аякса (наприер, чтобы провести её в дргом вложенном компоненте)
         * <code>
         * $this->arResult['AJAX'] = static::SKIP_AJAX_EXECUTION;
         * </code>
         */
        const SKIP_AJAX_EXECUTION = null;

        /**
         * @var null|array $arComponentParameters
         * @see BaseComponent::getComponentParameters()
         */
        static protected $arComponentParameters = null;

        /** @var \CMain $app */
        public $app;

        /**
         * @var array
         */
        protected $arUrlTemplates = array ();

        /**
         * @var array
         */
        protected $arVariableAliases = array ();

        /**
         * @var string
         */
        protected $componentRoute = 'index';

        /**
         * @var array
         */
        protected $arVariables = array ();

        /**
         * @var array
         */
        protected $arComponentVariables = array ();

        /**
         * @var array $componentRouteVariables
         * Данные, извлеченные из URL. Массив отсортирован так,чтобы порядок переменных соответствал
         * порядку аргументов вaction-функции контроллера
         */
        protected $componentRouteVariables = array ();

        /**
         * @var callable $callable
         * Динамически определяемая action-функция или класс для оработки запроса.
         */
        private $callable;

        /**
         * @var \ReflectionClass $reflection
         * Рефлексия поможет расширить возможности наследования.
         */
        private $reflection;

        /**
         * Инициализирует родной битриксовый класс и готовит полезные переменные
         * BaseComponent constructor.
         *
         * @param \CBitrixComponent|null $component
         */
        public function __construct ($component = null)
        {
            parent::__construct ($component);

            /** @var \CMain $APPLICATION */
            global $APPLICATION;
            $this->app = $APPLICATION;
            $this->reflection = new \ReflectionClass($this);

            $this->lang = new Lang(get_class ());
        }

        /**
         * Возвращает основные настройки для ипользования в .parameters.php у наследуемых компонентов
         *
         * @return array
         */
        public static function getArComponentParameters ()
        {
            return array (
                "PARAMETERS" => array (
                    "CACHE_TIME"   => array ('DEFAULT' => 3600),
                    "GREEDY_PARTS" => Array (
                        "PARENT"   => "ADDITIONAL_SETTINGS",
                        "NAME"     => self::$lang->getMessage ("MVC_GREEDY_PARTS"),
                        "TYPE"     => "STRING",
                        "MULTIPLE" => "N",
                        "DEFAULT"  => "",
                    ),
                ),
            );
        }

        /**
         * @param $arParams
         *
         * @return array
         */
        public function onPrepareComponentParams ($arParams)
        {
            $arParams = parent::onPrepareComponentParams ($arParams);
            $arParams = array_merge (static::getInternalComponentParameters (), $arParams);
            if (!isset($arParams['GREEDY_PARTS']))
            {
                $arParams['GREEDY_PARTS'] = array ();
            } elseif (is_string ($arParams['GREEDY_PARTS']))
            {
                $arParams['GREEDY_PARTS'] = trim (explode (',', $arParams['GREEDY_PARTS']));
            }

            return $arParams;
        }

        /**
         * При ыборе между заданием настроек компонента в данном масиве и в .parameters.php стоит руководствоваться
         * критерием: должен ли данный параметр меняться поьзователем/редактором из публичной части, или нет.
         * В первом случае стоит отдать предпочтение .parameters.php, во втором - данной переменной.
         *
         * Возможность переопределить эти параметры из публичной части всё равно остаётся, науке пока не изметно, баг это или фича...
         *
         * @return array $internalComponentParams - параметры компонента по-умолчанию
         */
        public static function getInternalComponentParameters ()
        {
            return array (
                'AJAX_CHECK_SESSID' => 'N',
                'VERBOSE'           => 'N',
                'CACHE_ACTION'      => [],
                'ACTION_CLASS'      => [],
                'LANG_LAZY_LOAD'    => 'N',
            );
        }

        /**
         *
         */
        public function executeComponent ()
        {
            if ($this->getSEF_Settings ())
            {
                $this->runAction ();
            } else
            {
                $this->showError (self::ERR_404);
            }
        }

        /**
         * Инициализирует ЧПУ из настроек компонента
         *
         * @return bool
         */
        protected function getSEF_Settings ()
        {
            if ($this->arParams['SEF_MODE'] == 'Y')
            {

                $engine = new \CComponentEngine($this);

                foreach ($this->arParams['GREEDY_PARTS'] as $part)
                {
                    $engine->addGreedyPart ($part);
                }

                $this->arUrlTemplates = \CComponentEngine::MakeComponentUrlTemplates (array (), $this->arParams["SEF_URL_TEMPLATES"]);
                $this->arVariableAliases = \CComponentEngine::MakeComponentVariableAliases (array (), $this->arParams["VARIABLE_ALIASES"]);
                $this->componentRoute = $engine->guessComponentPath ($this->arParams["SEF_FOLDER"], $this->arUrlTemplates, $this->arVariables);

                $this->parseGreedyPartsVariables ();

                if (!$this->componentRoute)
                {
                    if (static::isPathsEqual ($this->request->getRequestedPageDirectory (), $this->arParams["SEF_FOLDER"]))
                    {
                        $this->genCallable (true);

                        return true;
                    } else
                    {
                        return false;
                    }
                } else
                {
                    \CComponentEngine::InitComponentVariables (
                        $this->componentRoute,
                        $this->arComponentVariables,
                        $this->arVariableAliases,
                        $this->arVariables
                    );

                    //переставляем переменные в том порядке, к вотором они должны попасть в функцию
                    $componentParameters = $this->getComponentParameters ();
                    if (isset($componentParameters['PARAMETERS']['SEF_MODE'][$this->componentRoute]['VARIABLES']))
                    {
                        $parametersVars = $componentParameters['PARAMETERS']['SEF_MODE'][$this->componentRoute]['VARIABLES'];
                        foreach ($parametersVars as $varName)
                        {
                            if (isset($this->arVariables[$varName]))
                            {
                                $this->componentRouteVariables[] = $this->arVariables[$varName];
                            } else
                            {
                                $this->componentRouteVariables[] = null;
                            }
                        }
                    }

                    $this->genCallable ();

                    return true;
                }
            } else
            {
                $this->genCallable (true);

                return true;
            }
        }

        /**
         * Выполняет действие
         *
         * @throws NotImplementedException
         */
        protected function runAction ()
        {
            try
            {
                if ($this->request->isAjaxRequest () OR $this->request->isPost ())
                {
                    if ($this->arParams['AJAX_CHECK_SESSID'] == 'Y' AND !check_bitrix_sessid ())
                    {
                        throw new AjaxException('Session expired');
                    }

                    try
                    {
                        $this->callable[1] .= 'Ajax';
                        if (is_callable ($this->callable))
                        {
                            $this->arParams['AJAX_REQUEST'] =
                                'Y'; //необходимо, чтобы результаты ajax запроса кешировались отдельно от обычных запросов по тому е роуту.
                            $response = $this->callActionFunction ();
                            $this->arResult['AJAX'] = $response;
                            $this->sendAjaxResponse ();
                        } else
                        {
                            $this->throwNotImplemented ();
                        }
                    } catch (\Exception $e)
                    {
                        throw new AjaxException($e->getMessage (), $e->getCode (), $e);
                    }
                } elseif (is_callable ($this->callable))
                {
                    $responseCode = $this->callActionFunction ();
                    $this->setHttpResponse ($responseCode);
                } else
                {
                    $this->throwNotImplemented ();
                }
            } catch (AjaxException $e)
            {
                $response = array ('success' => false);
                if ($this->arParams['VERBOSE'] == 'Y')
                {
                    $response['exception'] = array (
                        'message' => $e->getMessage (),
                        'file'    => $e->getFile (),
                        'line'    => $e->getLine (),
                        'code'    => $e->getCode (),
                    );
                }

                $this->arResult['AJAX'] = $response;
                $this->sendAjaxResponse ();
            } catch (\Exception $e)
            {
                if ($this->isDebugMode ())
                {
                    throw $e;
                } else
                {
                    $this->showError (self::ERR_EXCEPTION, $e);
                }
            }
        }

        /**
         * Пользовательский обработчик страницы ошибок.
         * Не работает для AJAX-режима.
         *
         * @param string $type тип ошибки. Может быть константой ERR_EXCEPTION или ERR_404
         * @param mixed  $data данные об ошибке
         */
        protected function showError ($type, $data)
        {
            if ($this->app->RestartWorkarea ())
            {
                require (\Bitrix\Main\Application::getDocumentRoot () . "/404.php");
            }
        }

        /**
         * Разбивает "жадные" части урана отдельные значения
         */
        protected function parseGreedyPartsVariables ()
        {
            foreach ($this->arParams['GREEDY_PARTS'] as $part)
            {
                if (isset($this->arVariables[$part]))
                {
                    $this->arVariables[$part] = explode ('/', $this->arVariables[$part]);
                }
            }
        }

        /**
         * Сранивает два пути к каталогам.
         * Сделано во избежании ошибок с завершающими слешами
         *
         * @see \CComponentEngine::guessComponentPath()
         *
         * @param $path1
         * @param $path2
         *
         * @return bool
         */
        final public static function isPathsEqual ($path1, $path2)
        {
            $path1 = "/" . trim ($path1, "/ \t\n\r\0\x0B") . "/";
            $path2 = "/" . trim ($path2, "/ \t\n\r\0\x0B") . "/";

            return $path1 == $path2;
        }

        /**
         * Формирует callable-объект для вызова экшена.
         * Поддерживается аналог "Standalone actions" в Yii2: если в нстройках указано использовать другой класс в качестве экшена, то использует его.
         *
         * @param bool $default - сбрасывает настройки для вызова экшена "по-умолчанию"
         *
         * @see BaseComponent::$callable
         */
        private function genCallable ($default = false)
        {
            if ($default)
            {
                $this->componentRoute = 'index';
                $this->callable = array (
                    $this,
                    'action' . ucfirst ($this->componentRoute),
                );
            } else
            {
                if (isset($this->arParams['ACTION_CLASS'][$this->componentRoute]))
                {
                    $actionClass = $this->arParams['ACTION_CLASS'][$this->componentRoute];
                    if (is_object ($actionClass))
                    {
                        $this->callable = array (
                            $actionClass,
                            'run',
                        );
                    } elseif (class_exists ($actionClass))
                    {
                        $actionClassObject = new $actionClass();
                        $this->callable = array (
                            $actionClassObject,
                            'run',
                        );
                    }
                } else
                {
                    $this->callable = array (
                        $this,
                        'action' . ucfirst ($this->componentRoute),
                    );
                }
            }
        }

        /**
         * Получает параетры компонента из .parameters.php
         *
         * @return null|array
         */
        private function getComponentParameters ()
        {
            if (is_null (static::$arComponentParameters))
            {
                $componentDir = dirname ($this->reflection->getFileName ()) . '/';
                include $componentDir . '.parameters.php';
                static::$arComponentParameters = $arComponentParameters;
            }

            return static::$arComponentParameters;
        }

        /**
         * Выполняет непосредственно вызов нужной функции, делает первичную обработку ошибок.
         *
         * @return mixed результат выполнения action
         * @throws \Exception
         */
        private function callActionFunction ()
        {
            $cacheOptions = $this->configureCacheAction ();

            //Если кеш выключен, то выполняем так
            if ($cacheOptions === false)
            {

                $this->includeLangTree ();

                $response = call_user_func_array ($this->callable, $this->componentRouteVariables);
                if ($response === false)
                {
                    throw new \Exception("Error executing route's {$this->componentRoute} action");
                }

                $this->arResult['status_code'] = $response;
            } elseif (($cacheOptions === true AND $this->startResultCache ()) OR //Если включен кеш без дополнительных параметров
                (is_string ($cacheOptions) AND
                    $this->startResultCache ($this->arParams['CACHE_TIME'], $cacheOptions)) //Или если сдополнительными параметрами
            )
            {
                $this->includeLangTree ();

                //То всё рвно выполняем, но кеширование уже началось ;-)
                $response = call_user_func_array ($this->callable, $this->componentRouteVariables);

                if ($response === false)
                {
                    throw new \Exception("Error executing route's {$this->componentRoute} action");
                    $this->abortResultCache ();
                } elseif (!$this->isTemplateRendered ())
                {
                    $this->arResult['status_code'] = $response;
                    $componentPage = $this->componentRoute ? $this->componentRoute : "";
                    $this->includeComponentTemplate ($componentPage);
                }
            }

            return $this->arResult['status_code'];
        }

        /**
         * Отправляет результат запроса на клиент
         *
         * @internal
         */
        protected function sendAjaxResponse ()
        {
            $response = $this->arResult['AJAX'];
            if ($response !== self::SKIP_AJAX_EXECUTION)
            {

                if (is_array ($response) AND !isset($response ['success']))
                {
                    $response ['success'] = true;
                } elseif (is_bool ($response))
                {
                    $response = array ('success' => $response);
                } else
                {
                    $response = array (
                        'success' => true,
                        'data'    => $response,
                    );
                }

                $this->app->RestartBuffer ();
                print json_encode ($response);
                exit();
            }
        }

        /**
         * Выбрасывает исключение, когда нужный нам метод не реализован
         *
         * @param bool $ajax
         *
         * @throws NotImplementedException
         */
        private function throwNotImplemented ($ajax = false)
        {
            $className = $this->callable[0];
            if (is_object ($className))
            {
                $className = get_class ($className);
            }
            throw new NotImplementedException("Function {$className}::{$this->callable[1]} does not exists!");
        }

        /**
         * Устанавливает код ответа на запрос
         *
         * @param int $code
         *
         * @return int|string
         */
        protected function setHttpResponse ($code = null)
        {
            if (is_null ($code))
            {
                return false;
            }

            switch ($code)
            {
                case 100:
                    $text = 'Continue';
                    break;
                case 101:
                    $text = 'Switching Protocols';
                    break;
                case 200:
                    $text = 'OK';
                    break;
                case 201:
                    $text = 'Created';
                    break;
                case 202:
                    $text = 'Accepted';
                    break;
                case 203:
                    $text = 'Non-Authoritative Information';
                    break;
                case 204:
                    $text = 'No Content';
                    break;
                case 205:
                    $text = 'Reset Content';
                    break;
                case 206:
                    $text = 'Partial Content';
                    break;
                case 300:
                    $text = 'Multiple Choices';
                    break;
                case 301:
                    $text = 'Moved Permanently';
                    break;
                case 302:
                    $text = 'Moved Temporarily';
                    break;
                case 303:
                    $text = 'See Other';
                    break;
                case 304:
                    $text = 'Not Modified';
                    break;
                case 305:
                    $text = 'Use Proxy';
                    break;
                case 400:
                    $text = 'Bad Request';
                    break;
                case 401:
                    $text = 'Unauthorized';
                    break;
                case 402:
                    $text = 'Payment Required';
                    break;
                case 403:
                    $text = 'Forbidden';
                    break;
                case 404:
                    $text = 'Not Found';
                    break;
                case 405:
                    $text = 'Method Not Allowed';
                    break;
                case 406:
                    $text = 'Not Acceptable';
                    break;
                case 407:
                    $text = 'Proxy Authentication Required';
                    break;
                case 408:
                    $text = 'Request Time-out';
                    break;
                case 409:
                    $text = 'Conflict';
                    break;
                case 410:
                    $text = 'Gone';
                    break;
                case 411:
                    $text = 'Length Required';
                    break;
                case 412:
                    $text = 'Precondition Failed';
                    break;
                case 413:
                    $text = 'Request Entity Too Large';
                    break;
                case 414:
                    $text = 'Request-URI Too Large';
                    break;
                case 415:
                    $text = 'Unsupported Media Type';
                    break;
                case 500:
                    $text = 'Internal Server Error';
                    break;
                case 501:
                    $text = 'Not Implemented';
                    break;
                case 502:
                    $text = 'Bad Gateway';
                    break;
                case 503:
                    $text = 'Service Unavailable';
                    break;
                case 504:
                    $text = 'Gateway Time-out';
                    break;
                case 505:
                    $text = 'HTTP Version not supported';
                    break;
                default:
                    throw new \InvalidArgumentException('Unknown http status code "' . htmlentities ($code) . '"');
                    break;
            }
            $code = $code . ' ' . $text;
            Application::getInstance ()->getContext ()->getResponse ()->setStatus ($code);

            return $code;
        }

        /**
         * Определяет, включен ли режим отладки для сайта.
         * Можно переопределять функцию, в зависимости от устройства каждого конкретного сайта.
         *
         * @return bool
         */
        public function isDebugMode ()
        {
            if (defined ('BX_DEBUG') AND BX_DEBUG == 'Y')
            {
                return true;
            }

            return false;
        }

        /**
         * Получает дополнительные параметры кеширования для отдельного действия
         *
         * @return bool|mixed
         */
        private function configureCacheAction ()
        {
            if (isset($this->arParams['CACHE_ACTION'][$this->componentRoute]))
            {
                $cacheOption = $this->arParams['CACHE_ACTION'][$this->componentRoute];
                if (is_callable ($cacheOption))
                {
                    $cacheOption = call_user_func ($cacheOption, $this);

                    return $this->getActionCacheID ($cacheOption);
                } elseif (is_string ($cacheOption))
                {
                    if ($cacheOption == 'N')
                    {
                        return false;
                    }
                }
            }

            return $this->getActionCacheID ();
        }

        /**
         * Если наш класс отнаследован от другого класса, то может быть полезным включить ланг-файлы родительского
         * класса. Таким образом, переводы тоже будут наследоваться.
         * Наследование будет работать только в случае, если отключена "ленивая загрузка" переводов. Если включена -
         * то тоже может сработать, но при нескольких родителях может выдать непредсказуемый результат. Так же нельзя
         * будет переопределить сообщения, объявленные в каком-нибудь из родительских классов
         */
        private function includeLangTree ()
        {
            /** @var $class ReflectionClass */
            $class = $this->reflection;
            $paths = array ();
            while ($class = $class->getParentClass ())
            {
                if ($class->getName () == 'CBitrixComponent')
                {
                    break;
                }

                $path = str_replace ('class.php', 'component.php', $class->getFileName ());

                //При ленивой загрузке нормальное наслдедование переводов невозможно,
                //т.к. файлы для ленивой загрузки будут проверяться в рандомном порядке.
                if ($this->langLazyLoad ())
                {
                    Loc::loadMessages ($path);
                } else
                {
                    $paths[] = $path;
                }
            }

            //Если ленивоз загрузки нет, то порядок подключения имеет значение:
            //сначала классы-предки, поотм потомки.

            if ($this->langLazyLoad ())
            {
                Loc::loadMessages (__FILE__);
            } else
            {
                $paths = array_reverse ($paths);
                foreach ($paths as $path)
                {
                    Loc::loadLanguageFile ($path);
                }
                Loc::loadLanguageFile (__FILE__);
            }
        }

        /**
         * Проверяет, не выводили ли мы шаблон ранее.
         *
         * @return bool
         */
        protected function isTemplateRendered ()
        {
            return !is_null ($this->__template);
        }

        /**
         * Модифицирует ID кеша так, чтобы для каждого экшена кеш был свой.
         *
         * @param $cacheID
         *
         * @return string
         */
        private function getActionCacheID ($cacheID = null)
        {
            return $this->componentRoute . serialize ($this->componentRouteVariables) . (is_null ($cacheID) ? "" : $cacheID);
        }

        /**
         * @return bool
         */
        protected function langLazyLoad ()
        {
            return ($this->arParams['LANG_LAZY_LOAD'] == 'Y');
        }

        /**
         * Проверяет, может ли, к примеру, данный маршрут вызываться методом POST, или нет.
         *
         * @return bool
         */
        protected function checkRequestMethod ()
        {
            $componentParameters = $this->getComponentParameters ();
            if (isset($componentParameters['PARAMETERS']['SEF_MODE'][$this->componentRoute]['METHOD']))
            {
                $allowedMethods = $componentParameters['PARAMETERS']['SEF_MODE'][$this->componentRoute]['METHOD'];
                if (is_string ($allowedMethods))
                {
                    $allowedMethods = array ($allowedMethods);
                }
                if (!in_array ($this->request->getRequestMethod (), $allowedMethods))
                {
                    return false;
                }
            }

            return true;
        }
    }