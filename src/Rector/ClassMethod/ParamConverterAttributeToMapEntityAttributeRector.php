<?php

declare(strict_types=1);

namespace Rector\Symfony\Rector\ClassMethod;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use Rector\Core\Rector\AbstractRector;
use Rector\Core\ValueObject\PhpVersionFeature;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\Php80\NodeAnalyzer\PhpAttributeAnalyzer;
use Rector\VersionBonding\Contract\MinPhpVersionInterface;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * @changelog https://symfony.com/blog/new-in-symfony-6-2-built-in-cache-security-template-and-doctrine-attributes
 *
 * @see \Rector\Symfony\Tests\Rector\ClassMethod\ParamConverterAttributeToMapEntityAttributeRector\ParamConverterAttributeToMapEntityAttributeRectorTest
 */
final class ParamConverterAttributeToMapEntityAttributeRector extends AbstractRector implements MinPhpVersionInterface
{
    private const PARAM_CONVERTER_CLASS = 'Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter';

    private const ENTITY_CLASS = 'Sensio\\Bundle\\FrameworkExtraBundle\\Configuration\\Entity';

    private const MAP_ENTITY_CLASS = 'Symfony\Bridge\Doctrine\Attribute\MapEntity';

    public function __construct(
        private readonly PhpAttributeAnalyzer $phpAttributeAnalyzer,
    ) {
    }

    public function provideMinPhpVersion(): int
    {
        return PhpVersionFeature::ATTRIBUTES;
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Replace ParamConverter attribute with mappings with the MapEntity attribute',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
class SomeController
{
    #[Route('/blog/{date}/{slug}/comments/{comment_slug}')]
    #[ParamConverter('post', options: ['mapping' => ['date' => 'date', 'slug' => 'slug']])]
    #[ParamConverter('comment', options: ['mapping' => ['comment_slug' => 'slug']])]
    public function showComment(
        Post $post,
        Comment $comment
    ) {
    }
}
CODE_SAMPLE
                    ,
                    <<<'CODE_SAMPLE'
class SomeController
{
    #[Route('/blog/{date}/{slug}/comments/{comment_slug}')]
    public function showComment(
        #[\Symfony\Bridge\Doctrine\Attribute\MapEntity(mapping: ['date' => 'date', 'slug' => 'slug'])] Post $post,
        #[\Symfony\Bridge\Doctrine\Attribute\MapEntity(mapping: ['comment_slug' => 'slug'])] Comment $comment
    ) {
    }
}
CODE_SAMPLE
                ),
            ]
        );
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [ClassMethod::class];
    }

    public function refactor(Node $node): ?Node
    {
        if (
            ! $node instanceof ClassMethod ||
            ! $node->isPublic() ||
            ! $this->phpAttributeAnalyzer->hasPhpAttributes($node, [self::PARAM_CONVERTER_CLASS, self::ENTITY_CLASS])
        ) {
            return null;
        }

        $this->refactorParamConverter($node);

        return $node;
    }

    private function refactorParamConverter(ClassMethod $classMethod): void
    {
        foreach ($classMethod->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                if ($this->isNames($attr, [self::PARAM_CONVERTER_CLASS, self::ENTITY_CLASS])) {
                    $this->refactorAttribute($classMethod, $attr);
                }
            }
        }
    }

    private function refactorAttribute(ClassMethod $classMethod, Attribute $attribute): void
    {
        if (
            $attribute->args === [] ||
            ! $attribute->args[0]->value instanceof String_
        ) {
            return;
        }

        $optionsIndex = $this->getIndexForOptionsArg($attribute->args);
        $exprIndex = $this->getIndexForExprArg($attribute->args);
        if (! $optionsIndex && ! $exprIndex) {
            return;
        }

        $name = $attribute->args[0]->value->value;
        $mapping = $attribute->args[$optionsIndex]->value;
        $exprValue = $attribute->args[$exprIndex]->value;

        $newArguments = $this->getNewArguments($mapping, $exprValue);

        if ($newArguments === []) {
            return;
        }

        $this->removeNode($attribute->args[0]);
        if ($optionsIndex) {
            $this->removeNode($attribute->args[$optionsIndex]);
        }
        if ($exprIndex) {
            $this->removeNode($attribute->args[$exprIndex]);
        }
        $attribute->args = array_merge($attribute->args, $newArguments);
        $attribute->name = new FullyQualified(self::MAP_ENTITY_CLASS);

        $node = $attribute->getAttribute(AttributeKey::PARENT_NODE);
        if (! $node instanceof AttributeGroup) {
            return;
        }

        $this->addMapEntityAttribute($classMethod, $name, $node);
    }

    /**
     * @return Arg[]
     */
    private function getNewArguments(?Expr $mapping, ?Expr $exprValue): array
    {
        $newArguments = [];
        if ($mapping instanceof Array_) {
            $probablyEntity = false;
            foreach ($mapping->items as $item) {
                if (
                    ! $item instanceof ArrayItem ||
                    ! $item->key instanceof String_
                ) {
                    continue;
                }
                if (in_array($item->key->value, ['mapping', 'entity_manager'], true)) {
                    $probablyEntity = true;
                }
                $newArguments[] = new Arg($item->value, name: new Identifier($item->key->value));
            }

            if (! $probablyEntity) {
                return [];
            }
        }

        if ($exprValue instanceof String_) {
            $newArguments[] = new Arg($exprValue, \false, \false, [], new Identifier('expr'));
        }

        return $newArguments;
    }

    private function addMapEntityAttribute(
        ClassMethod $classMethod,
        string $variableName,
        AttributeGroup $attributeGroup
    ): void {
        foreach ($classMethod->params as $param) {
            if (
                ! $param->var instanceof Variable
            ) {
                continue;
            }
            if ($variableName === $param->var->name) {
                $param->attrGroups = [$attributeGroup];
                $this->removeNode($attributeGroup);
            }
        }
    }

    /**
     * @param Arg[] $args
     */
    private function getIndexForOptionsArg(array $args): ?int
    {
        foreach ($args as $key => $arg) {
            if ($arg->name instanceof Identifier && $arg->name->name === 'options') {
                return $key;
            }
        }

        return null;
    }

    /**
     * @param Arg[] $args
     */
    private function getIndexForExprArg(array $args): ?int
    {
        foreach ($args as $key => $arg) {
            if ($arg->name instanceof Identifier && $arg->name->name === 'expr') {
                return $key;
            }
        }
        return null;
    }
}
