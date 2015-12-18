<?php
/**
 * Opulence
 *
 * @link      https://www.opulencephp.com
 * @copyright Copyright (C) 2015 David Young
 * @license   https://github.com/opulencephp/Opulence/blob/master/LICENSE.md
 */
namespace Opulence\Framework\Bootstrappers\Validation;

use Opulence\Bootstrappers\ILazyBootstrapper;
use Opulence\Bootstrappers\Bootstrapper;
use Opulence\Ioc\IContainer;
use Opulence\Validation\IValidator;
use Opulence\Validation\Rules\Errors\Compilers\Compiler;
use Opulence\Validation\Rules\Errors\Compilers\ICompiler;
use Opulence\Validation\Rules\Errors\ErrorTemplateRegistry;
use Opulence\Validation\Rules\Factories\RulesFactory;
use Opulence\Validation\Rules\RuleExtensionRegistry;
use Opulence\Validation\Validator;

/**
 * Defines the validator bootstrapper
 */
abstract class ValidatorBootstrapper extends Bootstrapper implements ILazyBootstrapper
{
    /** @var RuleExtensionRegistry The rule extension registry */
    protected $ruleExtensionRegistry = null;
    /** @var ErrorTemplateRegistry The error template registry */
    protected $errorTemplateRegistry = null;
    /** @var ICompiler The error template compiler */
    protected $errorTemplateCompiler = null;
    /** @var RulesFactory The rules factory */
    protected $rulesFactory = null;
    /** @var IValidator The validator */
    protected $validator = null;

    /**
     * @inheritDoc
     */
    public function getBindings()
    {
        return [
            ErrorTemplateRegistry::class,
            ICompiler::class,
            RuleExtensionRegistry::class,
            RulesFactory::class,
            IValidator::class
        ];
    }

    /**
     * @inheritdoc
     */
    public function registerBindings(IContainer $container)
    {
        $this->ruleExtensionRegistry = $this->getRuleExtensionRegistry($container);
        $this->errorTemplateRegistry = $this->getErrorTemplateRegistry($container);
        $this->registerErrorTemplates($this->errorTemplateRegistry);
        $this->errorTemplateCompiler = $this->getErrorTemplateCompiler($container);
        $this->rulesFactory = $this->getRulesFactory($container);
        $this->validator = $this->getValidator($container);
        $container->bind(RuleExtensionRegistry::class, $this->ruleExtensionRegistry);
        $container->bind(ErrorTemplateRegistry::class, $this->errorTemplateRegistry);
        $container->bind(ICompiler::class, $this->errorTemplateCompiler);
        $container->bind(RulesFactory::class, $this->rulesFactory);
        $container->bind(IValidator::class, $this->validator);
    }

    /**
     * Registers the error templates
     *
     * @param ErrorTemplateRegistry $errorTemplateRegistry The registry to register to
     */
    abstract protected function registerErrorTemplates(ErrorTemplateRegistry $errorTemplateRegistry);

    /**
     * Gets the error template compiler
     *
     * @param IContainer $container The IoC container
     * @return ICompiler The error template compiler
     */
    protected function getErrorTemplateCompiler(IContainer $container)
    {
        return new Compiler();
    }

    /**
     * Gets the error template registry
     *
     * @param IContainer $container The IoC container
     * @return ErrorTemplateRegistry The error template registry
     */
    protected function getErrorTemplateRegistry(IContainer $container)
    {
        return new ErrorTemplateRegistry();
    }

    /**
     * Gets the rule extension registry
     *
     * @param IContainer $container The IoC container
     * @return RuleExtensionRegistry The rule extension registry
     */
    protected function getRuleExtensionRegistry(IContainer $container)
    {
        return new RuleExtensionRegistry();
    }

    /**
     * Gets the rules factory
     *
     * @param IContainer $container The IoC container
     * @return RulesFactory The rules factory
     */
    protected function getRulesFactory(IContainer $container)
    {
        return new RulesFactory(
            $this->ruleExtensionRegistry,
            $this->errorTemplateRegistry,
            $this->errorTemplateCompiler
        );
    }

    /**
     * Gets the validator
     *
     * @param IContainer $container The IoC container
     * @return IValidator The validator
     */
    protected function getValidator(IContainer $container)
    {
        return new Validator($this->rulesFactory, $this->ruleExtensionRegistry);
    }
}