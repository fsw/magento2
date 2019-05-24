<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\CompiledInterception\Generator;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\Code\Generator\CodeGeneratorInterface;
use Magento\Framework\Code\Generator\DefinedClasses;
use Magento\Framework\Code\Generator\Io;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Code\Generator\EntityAbstract;
use Magento\Framework\Config\ScopeInterface;
use Magento\Framework\Interception\DefinitionInterface;
use Magento\Framework\App\AreaList;

class CompiledInterceptor extends EntityAbstract
{
    /**
     * Entity type
     */
    const ENTITY_TYPE = 'interceptor';

    protected $plugins;

    protected $classMethods = null;

    protected $classProperties = null;

    protected $baseReflection = null;

    protected $areaList;

    /**
     * CompiledInterceptor constructor.
     * @param AreaList $areaList
     * @param null $sourceClassName
     * @param null $resultClassName
     * @param Io|null $ioObject
     * @param CodeGeneratorInterface|null $classGenerator
     * @param DefinedClasses|null $definedClasses
     * @param null $plugins
     */
    public function __construct(
        AreaList $areaList,
        $sourceClassName = null,
        $resultClassName = null,
        Io $ioObject = null,
        CodeGeneratorInterface $classGenerator = null,
        DefinedClasses $definedClasses = null,
        $plugins = null
    )
    {
        parent::__construct($sourceClassName,
            $resultClassName ,
            $ioObject,
            $classGenerator,
            $definedClasses);

        $this->areaList = $areaList;
        $this->plugins = $plugins;
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function setInterceptedMethods($interceptedMethods)
    {
        //NOOP
    }

    /**
     * @return array|null
     * @throws \ReflectionException
     */
    protected function _getClassMethods()
    {
        $this->generateMethodsAndProperties();
        return $this->classMethods;
    }

    /**
     * @return array|null
     * @throws \ReflectionException
     */
    protected function _getClassProperties()
    {
        $this->generateMethodsAndProperties();
        return $this->classProperties;
    }

    /**
     * @return array|void
     */
    protected function _getDefaultConstructorDefinition()
    {

    }

    /**
     * @return bool|string
     * @throws \ReflectionException
     */
    protected function _generateCode()
    {
        if ($this->getSourceClassReflection()->isInterface()) {
            return false;
        } else {
            $this->_classGenerator->setExtendedClass($this->getSourceClassName());
        }
        $this->generateMethodsAndProperties();
        return parent::_generateCode();
    }

    /**
     * @throws \ReflectionException
     */
    private function generateMethodsAndProperties()
    {
        if ($this->classMethods === null) {
            $this->classMethods = [];
            $this->classProperties = [];

            $this->injectPropertiesSettersToConstructor($this->getSourceClassReflection()->getConstructor(), [
                ScopeInterface::class => '____scope',
                ObjectManagerInterface::class => '____om',
            ]);
            $this->overrideMethodsAndGeneratePluginGetters($this->getSourceClassReflection());
        }
    }

    /**
     * @return \ReflectionClass
     * @throws \ReflectionException
     */
    private function getSourceClassReflection()
    {
        return new \ReflectionClass($this->getSourceClassName());
    }

    /**
     * Whether method is intercepted
     *
     * @param \ReflectionMethod $method
     * @return bool
     */
    protected function isInterceptedMethod(\ReflectionMethod $method)
    {
        return !($method->isConstructor() || $method->isFinal() || $method->isStatic() || $method->isDestructor()) &&
            !in_array($method->getName(), ['__sleep', '__wakeup', '__clone']);
    }

    private function overrideMethodsAndGeneratePluginGetters(\ReflectionClass $reflection)
    {
        $publicMethods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

        $allPlugins = [];
        foreach ($publicMethods as $method) {
            if ($this->isInterceptedMethod($method)) {
                $config = $this->getPluginsConfig($method, $allPlugins);
                if (!empty($config)) {
                    $this->classMethods[] = $this->getCompiledMethodInfo($method, $config);
                }
            }
        }
        foreach ($allPlugins as $plugins) {
            foreach ($plugins as $plugin) {
                $this->classMethods[] = $this->getPluginGetterInfo($plugin);
                $this->classProperties[] = $this->getPluginPropertyInfo($plugin);
            }
        }
    }

    private function injectPropertiesSettersToConstructor(\ReflectionMethod $parentConstructor = null, $properties = [])
    {
        if ($parentConstructor == null) {
            $parameters = [];
            $body = [];
        } else {
            $parameters = $parentConstructor->getParameters();
            foreach ($parameters as $parameter) {
                $parentCallParams[] = '$' . $parameter->getName();
            }
            $body = ["parent::__construct(" . implode(', ', $parentCallParams) .");"];
        }
        foreach ($properties as $type => $name) {
            $this->_classGenerator->addUse($type);
            $this->classProperties[] = [
                'name' => $name,
                'visibility' => 'private',
                'docblock' => [
                    'tags' => [['name' => 'var', 'description' => substr(strrchr($type, "\\"), 1)]],
                ]
            ];
        }
        $extraParams = $properties;
        $extraSetters = array_combine($properties, $properties);
        foreach ($parameters as $parameter) {
            if ($parameter->getType()) {
                $type = $parameter->getType()->getName();
                if (isset($properties[$type])) {
                    $extraSetters[$properties[$type]] = $parameter->getName();
                    unset($extraParams[$type]);
                }
            }
        }
        $parameters = array_map(array($this, '_getMethodParameterInfo'), $parameters);
        /* foreach ($extraParams as $type => $name) {
            array_unshift($parameters, [
                'name' => $name,
                'type' => $type
            ]);
        } */
        foreach ($extraSetters as $name => $paramName) {
            array_unshift($body, "\$this->$name = \$$paramName;");
        }
        foreach ($extraParams as $type => $name) {
            array_unshift($body, "//TODO fix di in production mode");
            array_unshift($body, "\$$name = \\Magento\\Framework\\App\\ObjectManager::getInstance()->get(\\$type::class);");
        }

        $this->classMethods[] = [
            'name' => '__construct',
            'parameters' => $parameters,
            'body' => implode("\n", $body),
            'docblock' => ['shortDescription' => '{@inheritdoc}'],
        ];

    }

    private function addCodeSubBlock(&$body, $sub, $indent = 1)
    {
        foreach ($sub as $line) {
            $body[] = str_repeat("\t", $indent) . $line;
        }
    }

    /**
     * @param $plugins
     * @param $methodName
     * @param $extraParams
     * @param $parametersList
     * @return array
     */
    private function compileBeforePlugins($plugins, $methodName, $extraParams, $parametersList)
    {
        $lines = [];
        foreach ($plugins as $plugin) {
            $call = "\$this->" . $this->getGetterName($plugin) . "()->$methodName(\$this$extraParams);";

            if (!empty($parametersList)) {
                $lines[] = "\$beforeResult = " . $call;
                $lines[] = "if (\$beforeResult !== null) list({$parametersList}) = (array)\$beforeResult;";
            } else {
                $lines[] = $call;
            }
            $lines[] = "";
        }
        return $lines;
    }

    /**
     * @param $methodName
     * @param $plugin
     * @param $capitalizedName
     * @param $extraParams
     * @param $parameters
     * @param $returnVoid
     * @return array
     */
    private function compileAroundPlugin($methodName, $plugin, $capitalizedName, $extraParams, $parameters, $returnVoid)
    {
        $lines = [];
        $lines[] = "\$this->" . $this->getGetterName($plugin) . "()->around$capitalizedName(\$this, function({$this->getParameterListForNextCallback($parameters)}){";
        $this->addCodeSubBlock($lines, $this->getMethodSourceFromConfig($methodName, $plugin['next'] ?: [], $parameters, $returnVoid));
        $lines[] = "}$extraParams);";
        return $lines;
    }

    /**
     * @param $plugins
     * @param $methodName
     * @param $extraParams
     * @param $returnVoid
     * @return array
     */
    private function compileAfterPlugins($plugins, $methodName, $extraParams, $returnVoid)
    {
        $lines = [];
        foreach ($plugins as $plugin) {
            if (!$returnVoid) {
                $lines[] = ["((\$tmp = \$this->" . $this->getGetterName($plugin) . "()->$methodName(\$this, \$result$extraParams)) !== null) ? \$tmp : \$result;"];
            } else {
                $lines[] = ["\$this->" . $this->getGetterName($plugin) . "()->$methodName(\$this, null$extraParams);"];
            }
        }
        return $lines;
    }

    /**
     * @param \ReflectionMethod $method
     * @param $conf
     * @param $parameters
     * @return array
     */
    private function getMethodSourceFromConfig($methodName, $conf, $parameters, $returnVoid)
    {
        $first = true;
        $capitalizedName = ucfirst($methodName);
        $parametersList = $this->getParameterList($parameters);
        $extraParams = empty($parameters) ? '' : (', ' . $parametersList);

        if (isset($conf[DefinitionInterface::LISTENER_BEFORE])) {
            $body = $this->compileBeforePlugins($conf[DefinitionInterface::LISTENER_BEFORE], 'before' . $capitalizedName, $extraParams, $parametersList);
        } else {
            $body = [];
        }

        $resultChain = [];
        if (isset($conf[DefinitionInterface::LISTENER_AROUND])) {
            $resultChain[] = $this->compileAroundPlugin($methodName, $conf[DefinitionInterface::LISTENER_AROUND],  $capitalizedName, $extraParams, $parameters, $returnVoid);
        } else {
            $resultChain[] = ["parent::{$methodName}({$this->getParameterList($parameters)});"];
        }

        if (isset($conf[DefinitionInterface::LISTENER_AFTER])) {
            $resultChain = array_merge($resultChain, $this->compileAfterPlugins($conf[DefinitionInterface::LISTENER_AFTER], 'after' . $capitalizedName, $extraParams, $returnVoid));
        }
        foreach ($resultChain as $lp => $piece) {
            if ($first) $first = false; else $body[] = "";
            if (!$returnVoid) {
                $piece[0] = (($lp + 1 == count($resultChain)) ? "return " : "\$result = ") . $piece[0];
            }
            foreach ($piece as $line) {
                $body[] = $line;
            }
        }
        return $body;
    }

    /**
     * @param array $parameters
     * @return string
     */
    private function getParameterListForNextCallback(array $parameters)
    {
        $ret = [];
        foreach ($parameters as $parameter) {
            $ret [] =
                ($parameter->isPassedByReference() ? '&' : '') .
                "\${$parameter->getName()}" .
                ($parameter->isDefaultValueAvailable() ?
                    ' = ' . ($parameter->isDefaultValueConstant() ?
                        $parameter->getDefaultValueConstantName() :
                        str_replace("\n", '', var_export($parameter->getDefaultValue(), true))) :
                    '');
        }
        return implode(', ', $ret);
    }

    /**
     * @param \ReflectionParameter[]  $parameters
     * @return string
     */
    private function getParameterList(array $parameters)
    {
        $ret = [];
        foreach ($parameters as $parameter) {
            $ret [] = "\${$parameter->getName()}";
        }
        return implode(', ', $ret);
    }

    /**
     * @param $plugin
     * @return string
     */
    private function getGetterName($plugin)
    {
        return '____plugin_' . $plugin['clean_name'];
    }

    /**
     * @param $plugin
     * @return array
     */
    private function getPluginPropertyInfo($plugin)
    {
        return [
            'name' => '____plugin_' . $plugin['clean_name'],
            'visibility' => 'private',
            'docblock' => [
                'tags' => [['name' => 'var', 'description' => '\\' . $plugin['class']]],
            ]
        ];
    }

    /**
     * @param $plugin
     * @return array
     */
    private function getPluginGetterInfo($plugin)
    {
        $body = [];
        $varName = "\$this->____plugin_" . $plugin['clean_name'];

        $body[] = "if ($varName === null) {";
        $body[] = "\t$varName = \$this->____om->get(\\" . "{$plugin['class']}::class);";
        $body[] = "}";
        $body[] = "return $varName;";

        return [
            'name' => $this->getGetterName($plugin),
            'parameters' => [],
            'body' => implode("\n", $body),
            'returnType' => $plugin['class'],
            'docblock' => [
                'shortDescription' => 'plugin "' . $plugin['code'] . '"' . "\n" . '@return \\' . $plugin['class']
            ],
        ];
    }

    /**
     * @param \ReflectionMethod $method
     * @param $config
     * @return array
     */
    private function getCompiledMethodInfo(\ReflectionMethod $method, $config)
    {
        $parameters = $method->getParameters();
        $returnsVoid = ($method->hasReturnType() && $method->getReturnType()->getName() == 'void');

        $body = [
            'switch($this->____scope->getCurrentScope()){'
        ];

        $cases = [];
        //group cases by config
        foreach ($config as $scope => $conf) {
            $key = md5(serialize($conf));
            if (!isset($cases[$key])) $cases[$key] = ['cases'=>[], 'conf'=>$conf];
            $cases[$key]['cases'][] = "\tcase '$scope':";
        }
        //call parent method for scopes with no plugins (or when no scope is set)
        $cases[] = ['cases'=>["\tdefault:"], 'conf'=>[]];

        foreach ($cases as $case) {
            $body = array_merge($body, $case['cases']);
            $this->addCodeSubBlock($body, $this->getMethodSourceFromConfig($method->getName(), $case['conf'], $parameters, $returnsVoid), 2);
            if ($returnsVoid) {
                $body[] = "\t\tbreak;";
            }
        }

        $body[] = "}";
        $returnType = $method->getReturnType();
        $returnTypeValue = $returnType
            ? ($returnType->allowsNull() ? '?' : '') .$returnType->getName()
            : null;
        if ($returnTypeValue === 'self') {
            $returnTypeValue = $method->getDeclaringClass()->getName();
        }
        return [
            'name' => ($method->returnsReference() ? '& ' : '') . $method->getName(),
            'parameters' =>array_map(array($this, '_getMethodParameterInfo'), $parameters),
            'body' => implode("\n", $body),
            'returnType' => $returnTypeValue,
            'docblock' => ['shortDescription' => '{@inheritdoc}'],
        ];
    }

    private function getPluginInfo(CompiledPluginList $plugins, $code, $className, &$allPlugins, $next = null)
    {
        $className = $plugins->getPluginType($className, $code);
        if (!isset($allPlugins[$code])) $allPlugins[$code] = [];
        if (empty($allPlugins[$code][$className])) {
            $suffix = count($allPlugins[$code]) ? count($allPlugins[$code]) + 1 : '';
            $allPlugins[$code][$className] = [
                'code' => $code,
                'class' => $className,
                'clean_name' => preg_replace("/[^A-Za-z0-9_]/", '_', $code . $suffix)
            ];
        }
        $result = $allPlugins[$code][$className];
        $result['next'] = $next;
        return $result;

    }

    private function getPluginsChain(CompiledPluginList $plugins, $className, $method, &$allPlugins, $next = '__self')
    {
        $result = $plugins->getNext($className, $method, $next);
        if(!empty($result[DefinitionInterface::LISTENER_BEFORE])) {
            foreach ($result[DefinitionInterface::LISTENER_BEFORE] as $k => $code) {
                $result[DefinitionInterface::LISTENER_BEFORE][$k] = $this->getPluginInfo($plugins, $code, $className, $allPlugins);
            }
        }
        if(!empty($result[DefinitionInterface::LISTENER_AFTER])) {
            foreach ($result[DefinitionInterface::LISTENER_AFTER] as $k => $code) {
                $result[DefinitionInterface::LISTENER_AFTER][$k] = $this->getPluginInfo($plugins, $code, $className, $allPlugins);
            }
        }
        if (isset($result[DefinitionInterface::LISTENER_AROUND])) {
            $result[DefinitionInterface::LISTENER_AROUND] = $this->getPluginInfo($plugins, $result[DefinitionInterface::LISTENER_AROUND], $className, $allPlugins,
                $this->getPluginsChain($plugins, $className, $method, $allPlugins, $result[DefinitionInterface::LISTENER_AROUND]));
        }
        return $result;
    }

    private function getPluginsConfig(\ReflectionMethod $method, &$allPlugins)
    {
        $className = ltrim($this->getSourceClassName(), '\\');

        $result = array();
        if ($this->plugins === null) {
            $this->plugins = [];
            foreach ($this->areaList->getCodes() as $scope) {
                $this->plugins[$scope] = new CompiledPluginList(ObjectManager::getInstance(), $scope);
            }
        }
        foreach ($this->plugins as $scope => $pluginsList) {
            $pluginChain = $this->getPluginsChain($pluginsList, $className, $method->getName(), $allPlugins);
            if ($pluginChain) {
                $result[$scope] = $pluginChain;
            }

        }
        return $result;
    }
}
