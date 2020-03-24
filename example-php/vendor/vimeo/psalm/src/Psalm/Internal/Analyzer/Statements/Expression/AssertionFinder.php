<?php
namespace Psalm\Internal\Analyzer\Statements\Expression;

use PhpParser;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\Analyzer\Statements\ExpressionAnalyzer;
use Psalm\Internal\Analyzer\TypeAnalyzer;
use Psalm\CodeLocation;
use Psalm\FileSource;
use Psalm\Issue\DocblockTypeContradiction;
use Psalm\Issue\RedundantCondition;
use Psalm\Issue\RedundantConditionGivenDocblockType;
use Psalm\Issue\TypeDoesNotContainNull;
use Psalm\Issue\TypeDoesNotContainType;
use Psalm\Issue\UnevaluatedCode;
use Psalm\IssueBuffer;
use Psalm\StatementsSource;
use Psalm\Type;
use function substr;
use function count;
use function strtolower;
use function in_array;
use function array_merge;
use function strpos;
use function is_int;

/**
 * @internal
 */
class AssertionFinder
{
    const ASSIGNMENT_TO_RIGHT = 1;
    const ASSIGNMENT_TO_LEFT = -1;

    /**
     * Gets all the type assertions in a conditional
     *
     * @param string|null $this_class_name
     *
     * @return array<string, non-empty-list<non-empty-list<string>>>|null
     */
    public static function scrapeAssertions(
        PhpParser\Node\Expr $conditional,
        $this_class_name,
        FileSource $source,
        Codebase $codebase = null,
        bool $inside_negation = false,
        bool $cache = true
    ) {
        $if_types = [];

        if ($conditional instanceof PhpParser\Node\Expr\Instanceof_) {
            $instanceof_types = self::getInstanceOfTypes($conditional, $this_class_name, $source);

            if ($instanceof_types) {
                $var_name = ExpressionAnalyzer::getArrayVarId(
                    $conditional->expr,
                    $this_class_name,
                    $source
                );

                if ($var_name) {
                    $if_types[$var_name] = [$instanceof_types];

                    $var_type = $source instanceof StatementsAnalyzer
                        ? $source->node_data->getType($conditional->expr)
                        : null;

                    foreach ($instanceof_types as $instanceof_type) {
                        if ($instanceof_type[0] === '=') {
                            $instanceof_type = substr($instanceof_type, 1);
                        }

                        if ($codebase
                            && $var_type
                            && $inside_negation
                            && $source instanceof StatementsAnalyzer
                        ) {
                            if ($codebase->interfaceExists($instanceof_type)) {
                                continue;
                            }

                            $instanceof_type = new Type\Union([
                                new Type\Atomic\TNamedObject($instanceof_type)
                            ]);

                            if (!TypeAnalyzer::canExpressionTypesBeIdentical(
                                $codebase,
                                $instanceof_type,
                                $var_type
                            )) {
                                if ($var_type->from_docblock) {
                                    if (IssueBuffer::accepts(
                                        new RedundantConditionGivenDocblockType(
                                            $var_type->getId() . ' does not contain ' . $instanceof_type,
                                            new CodeLocation($source, $conditional)
                                        ),
                                        $source->getSuppressedIssues()
                                    )) {
                                        // fall through
                                    }
                                } else {
                                    if (IssueBuffer::accepts(
                                        new RedundantCondition(
                                            $var_type->getId() . ' cannot be identical to ' . $instanceof_type,
                                            new CodeLocation($source, $conditional)
                                        ),
                                        $source->getSuppressedIssues()
                                    )) {
                                        // fall through
                                    }
                                }
                            }
                        }
                    }
                }
            }

            return $if_types;
        }

        $var_name = ExpressionAnalyzer::getArrayVarId(
            $conditional,
            $this_class_name,
            $source
        );

        if ($var_name) {
            $if_types[$var_name] = [['!falsy']];

            if (!$conditional instanceof PhpParser\Node\Expr\MethodCall
                && !$conditional instanceof PhpParser\Node\Expr\StaticCall
            ) {
                return $if_types;
            }
        }

        if ($conditional instanceof PhpParser\Node\Expr\Assign) {
            $var_name = ExpressionAnalyzer::getArrayVarId(
                $conditional->var,
                $this_class_name,
                $source
            );

            if ($var_name) {
                $if_types[$var_name] = [['!falsy']];
            }

            return $if_types;
        }

        if ($conditional instanceof PhpParser\Node\Expr\BooleanNot) {
            $expr_assertions = null;

            if ($cache && $source instanceof StatementsAnalyzer) {
                $expr_assertions = $source->node_data->getAssertions($conditional->expr);
            }

            if ($expr_assertions === null) {
                $expr_assertions = self::scrapeAssertions(
                    $conditional->expr,
                    $this_class_name,
                    $source,
                    $codebase,
                    !$inside_negation,
                    $cache
                );

                if ($cache && $source instanceof StatementsAnalyzer) {
                    $source->node_data->setAssertions($conditional->expr, $expr_assertions);
                }
            }

            if ($expr_assertions === null) {
                throw new \UnexpectedValueException('Assertions should be set');
            }

            $if_types = \Psalm\Type\Algebra::negateTypes($expr_assertions);
            return $if_types;
        }

        if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\Identical ||
            $conditional instanceof PhpParser\Node\Expr\BinaryOp\Equal
        ) {
            $if_types = self::scrapeEqualityAssertions(
                $conditional,
                $this_class_name,
                $source,
                $codebase,
                false,
                $cache
            );

            return $if_types;
        }

        if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\NotIdentical ||
            $conditional instanceof PhpParser\Node\Expr\BinaryOp\NotEqual
        ) {
            $if_types = self::scrapeInequalityAssertions(
                $conditional,
                $this_class_name,
                $source,
                $codebase,
                false,
                $cache
            );

            return $if_types;
        }

        if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\Greater
            || $conditional instanceof PhpParser\Node\Expr\BinaryOp\GreaterOrEqual
        ) {
            $min_count = null;
            $count_equality_position = self::hasNonEmptyCountEqualityCheck($conditional, $min_count);
            $typed_value_position = self::hasTypedValueComparison($conditional, $source);

            if ($count_equality_position) {
                if ($count_equality_position === self::ASSIGNMENT_TO_RIGHT) {
                    $counted_expr = $conditional->left;
                } else {
                    throw new \UnexpectedValueException('$count_equality_position value');
                }

                /** @var PhpParser\Node\Expr\FuncCall $counted_expr */
                $var_name = ExpressionAnalyzer::getArrayVarId(
                    $counted_expr->args[0]->value,
                    $this_class_name,
                    $source
                );

                if ($var_name) {
                    if (self::hasReconcilableNonEmptyCountEqualityCheck($conditional)) {
                        $if_types[$var_name] = [['non-empty-countable']];
                    } else {
                        if ($min_count) {
                            $if_types[$var_name] = [['=has-at-least-' . $min_count]];
                        } else {
                            $if_types[$var_name] = [['=non-empty-countable']];
                        }
                    }
                }

                return $if_types;
            }

            if ($typed_value_position) {
                if ($typed_value_position === self::ASSIGNMENT_TO_RIGHT) {
                    /** @var PhpParser\Node\Expr $conditional->right */
                    $var_name = ExpressionAnalyzer::getArrayVarId(
                        $conditional->left,
                        $this_class_name,
                        $source
                    );
                } elseif ($typed_value_position === self::ASSIGNMENT_TO_LEFT) {
                    $var_name = null;
                } else {
                    throw new \UnexpectedValueException('$typed_value_position value');
                }

                if ($var_name) {
                    $if_types[$var_name] = [['=isset']];
                }

                return $if_types;
            }

            return [];
        }

        if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\Smaller
            || $conditional instanceof PhpParser\Node\Expr\BinaryOp\SmallerOrEqual
        ) {
            $min_count = null;
            $count_equality_position = self::hasNonEmptyCountEqualityCheck($conditional, $min_count);
            $typed_value_position = self::hasTypedValueComparison($conditional, $source);

            if ($count_equality_position) {
                if ($count_equality_position === self::ASSIGNMENT_TO_LEFT) {
                    $count_expr = $conditional->right;
                } else {
                    throw new \UnexpectedValueException('$count_equality_position value');
                }

                /** @var PhpParser\Node\Expr\FuncCall $count_expr */
                $var_name = ExpressionAnalyzer::getArrayVarId(
                    $count_expr->args[0]->value,
                    $this_class_name,
                    $source
                );

                if ($var_name) {
                    if ($min_count) {
                        $if_types[$var_name] = [['=has-at-least-' . $min_count]];
                    } else {
                        $if_types[$var_name] = [['=non-empty-countable']];
                    }
                }

                return $if_types;
            }

            if ($typed_value_position) {
                if ($typed_value_position === self::ASSIGNMENT_TO_RIGHT) {
                    $var_name = null;
                } elseif ($typed_value_position === self::ASSIGNMENT_TO_LEFT) {
                    /** @var PhpParser\Node\Expr $conditional->left */
                    $var_name = ExpressionAnalyzer::getArrayVarId(
                        $conditional->right,
                        $this_class_name,
                        $source
                    );
                } else {
                    throw new \UnexpectedValueException('$typed_value_position value');
                }

                if ($var_name) {
                    $if_types[$var_name] = [['=isset']];
                }

                return $if_types;
            }

            return [];
        }

        if ($conditional instanceof PhpParser\Node\Expr\FuncCall) {
            $if_types = self::processFunctionCall($conditional, $this_class_name, $source, false);

            return $if_types;
        }

        if ($conditional instanceof PhpParser\Node\Expr\MethodCall
            || $conditional instanceof PhpParser\Node\Expr\StaticCall
        ) {
            $custom_assertions = self::processCustomAssertion($conditional, $this_class_name, $source, false);

            if ($custom_assertions) {
                return $custom_assertions;
            }

            return $if_types;
        }

        if ($conditional instanceof PhpParser\Node\Expr\Empty_) {
            $var_name = ExpressionAnalyzer::getArrayVarId(
                $conditional->expr,
                $this_class_name,
                $source
            );

            if ($var_name) {
                $if_types[$var_name] = [['empty']];
            }

            return $if_types;
        }

        if ($conditional instanceof PhpParser\Node\Expr\Isset_) {
            foreach ($conditional->vars as $isset_var) {
                $var_name = ExpressionAnalyzer::getArrayVarId(
                    $isset_var,
                    $this_class_name,
                    $source
                );

                if ($var_name) {
                    $if_types[$var_name] = [['isset']];
                } else {
                    // look for any variables we *can* use for an isset assertion
                    $array_root = $isset_var;

                    while ($array_root instanceof PhpParser\Node\Expr\ArrayDimFetch && !$var_name) {
                        $array_root = $array_root->var;

                        $var_name = ExpressionAnalyzer::getArrayVarId(
                            $array_root,
                            $this_class_name,
                            $source
                        );
                    }

                    if ($var_name) {
                        $if_types[$var_name] = [['=isset']];
                    }
                }
            }

            return $if_types;
        }

        if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\Coalesce) {
            return self::scrapeAssertions(
                new PhpParser\Node\Expr\Ternary(
                    new PhpParser\Node\Expr\Isset_(
                        [$conditional->left]
                    ),
                    $conditional->left,
                    $conditional->right,
                    $conditional->getAttributes()
                ),
                $this_class_name,
                $source,
                $codebase,
                $inside_negation,
                false
            );
        }

        return [];
    }

    /**
     * @param PhpParser\Node\Expr\BinaryOp\Identical|PhpParser\Node\Expr\BinaryOp\Equal $conditional
     * @param string|null $this_class_name
     *
     * @return array<string, non-empty-list<non-empty-list<string>>>
     */
    private static function scrapeEqualityAssertions(
        PhpParser\Node\Expr\BinaryOp $conditional,
        $this_class_name,
        FileSource $source,
        Codebase $codebase = null,
        bool $inside_negation = false,
        bool $cache = true
    ) {
        $if_types = [];

        $null_position = self::hasNullVariable($conditional);
        $false_position = self::hasFalseVariable($conditional);
        $true_position = self::hasTrueVariable($conditional);
        $empty_array_position = self::hasEmptyArrayVariable($conditional);
        $gettype_position = self::hasGetTypeCheck($conditional);
        $min_count = null;
        $count_equality_position = self::hasNonEmptyCountEqualityCheck($conditional, $min_count);

        if ($null_position !== null) {
            if ($null_position === self::ASSIGNMENT_TO_RIGHT) {
                $base_conditional = $conditional->left;
            } elseif ($null_position === self::ASSIGNMENT_TO_LEFT) {
                $base_conditional = $conditional->right;
            } else {
                throw new \UnexpectedValueException('$null_position value');
            }

            $var_name = ExpressionAnalyzer::getArrayVarId(
                $base_conditional,
                $this_class_name,
                $source
            );

            if ($var_name && $base_conditional instanceof PhpParser\Node\Expr\Assign) {
                $var_name = '=' . $var_name;
            }

            if ($var_name) {
                if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\Identical) {
                    $if_types[$var_name] = [['null']];
                } else {
                    $if_types[$var_name] = [['falsy']];
                }
            }

            if ($codebase
                && $source instanceof StatementsAnalyzer
                && ($var_type = $source->node_data->getType($base_conditional))
                && $conditional instanceof PhpParser\Node\Expr\BinaryOp\Identical
            ) {
                $null_type = Type::getNull();

                if (!TypeAnalyzer::isContainedBy(
                    $codebase,
                    $var_type,
                    $null_type
                ) && !TypeAnalyzer::isContainedBy(
                    $codebase,
                    $null_type,
                    $var_type
                )) {
                    if ($var_type->from_docblock) {
                        if (IssueBuffer::accepts(
                            new DocblockTypeContradiction(
                                $var_type . ' does not contain null',
                                new CodeLocation($source, $conditional)
                            ),
                            $source->getSuppressedIssues()
                        )) {
                            // fall through
                        }
                    } else {
                        if (IssueBuffer::accepts(
                            new TypeDoesNotContainNull(
                                $var_type . ' does not contain null',
                                new CodeLocation($source, $conditional)
                            ),
                            $source->getSuppressedIssues()
                        )) {
                            // fall through
                        }
                    }
                }
            }

            return $if_types;
        }

        if ($true_position) {
            if ($true_position === self::ASSIGNMENT_TO_RIGHT) {
                $base_conditional = $conditional->left;
            } elseif ($true_position === self::ASSIGNMENT_TO_LEFT) {
                $base_conditional = $conditional->right;
            } else {
                throw new \UnexpectedValueException('Unrecognised position');
            }

            if ($base_conditional instanceof PhpParser\Node\Expr\FuncCall) {
                $if_types = self::processFunctionCall(
                    $base_conditional,
                    $this_class_name,
                    $source,
                    false
                );
            } else {
                $var_name = ExpressionAnalyzer::getArrayVarId(
                    $base_conditional,
                    $this_class_name,
                    $source
                );

                if ($var_name) {
                    if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\Identical) {
                        $if_types[$var_name] = [['true']];
                    } else {
                        $if_types[$var_name] = [['!falsy']];
                    }
                } else {
                    $base_assertions = null;

                    if ($source instanceof StatementsAnalyzer && $cache) {
                        $base_assertions = $source->node_data->getAssertions($base_conditional);
                    }

                    if ($base_assertions === null) {
                        $base_assertions = self::scrapeAssertions(
                            $base_conditional,
                            $this_class_name,
                            $source,
                            $codebase,
                            $inside_negation,
                            $cache
                        );

                        if ($source instanceof StatementsAnalyzer && $cache) {
                            $source->node_data->setAssertions($base_conditional, $base_assertions);
                        }
                    }

                    if ($base_assertions === null) {
                        throw new \UnexpectedValueException('Assertions should be set');
                    }

                    $if_types = $base_assertions;
                }
            }

            if ($codebase
                && $source instanceof StatementsAnalyzer
                && ($var_type = $source->node_data->getType($base_conditional))
            ) {
                if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\Identical) {
                    $true_type = Type::getTrue();

                    if (!TypeAnalyzer::isContainedBy(
                        $codebase,
                        $var_type,
                        $true_type
                    ) && !TypeAnalyzer::isContainedBy(
                        $codebase,
                        $true_type,
                        $var_type
                    )) {
                        if ($var_type->from_docblock) {
                            if (IssueBuffer::accepts(
                                new DocblockTypeContradiction(
                                    $var_type . ' does not contain true',
                                    new CodeLocation($source, $conditional)
                                ),
                                $source->getSuppressedIssues()
                            )) {
                                // fall through
                            }
                        } else {
                            if (IssueBuffer::accepts(
                                new TypeDoesNotContainType(
                                    $var_type . ' does not contain true',
                                    new CodeLocation($source, $conditional)
                                ),
                                $source->getSuppressedIssues()
                            )) {
                                // fall through
                            }
                        }
                    }
                }
            }

            return $if_types;
        }

        if ($false_position) {
            if ($false_position === self::ASSIGNMENT_TO_RIGHT) {
                $base_conditional = $conditional->left;
            } elseif ($false_position === self::ASSIGNMENT_TO_LEFT) {
                $base_conditional = $conditional->right;
            } else {
                throw new \UnexpectedValueException('$false_position value');
            }

            if ($base_conditional instanceof PhpParser\Node\Expr\FuncCall) {
                $if_types = self::processFunctionCall(
                    $base_conditional,
                    $this_class_name,
                    $source,
                    true
                );
            } else {
                $var_name = ExpressionAnalyzer::getArrayVarId(
                    $base_conditional,
                    $this_class_name,
                    $source
                );

                if ($var_name) {
                    if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\Identical) {
                        $if_types[$var_name] = [['false']];
                    } else {
                        $if_types[$var_name] = [['falsy']];
                    }
                } else {
                    $base_assertions = null;

                    if ($source instanceof StatementsAnalyzer && $cache) {
                        $base_assertions = $source->node_data->getAssertions($base_conditional);
                    }

                    if ($base_assertions === null) {
                        $base_assertions = self::scrapeAssertions(
                            $base_conditional,
                            $this_class_name,
                            $source,
                            $codebase,
                            $inside_negation,
                            $cache
                        );

                        if ($source instanceof StatementsAnalyzer && $cache) {
                            $source->node_data->setAssertions($base_conditional, $base_assertions);
                        }
                    }

                    if ($base_assertions === null) {
                        throw new \UnexpectedValueException('Assertions should be set');
                    }

                    $notif_types = $base_assertions;

                    if (count($notif_types) === 1) {
                        $if_types = \Psalm\Type\Algebra::negateTypes($notif_types);
                    }
                }
            }

            if ($codebase
                && $source instanceof StatementsAnalyzer
                && ($var_type = $source->node_data->getType($base_conditional))
            ) {
                if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\Identical) {
                    $false_type = Type::getFalse();

                    if (!TypeAnalyzer::isContainedBy(
                        $codebase,
                        $var_type,
                        $false_type
                    ) && !TypeAnalyzer::isContainedBy(
                        $codebase,
                        $false_type,
                        $var_type
                    )) {
                        if ($var_type->from_docblock) {
                            if (IssueBuffer::accepts(
                                new DocblockTypeContradiction(
                                    $var_type . ' does not contain false',
                                    new CodeLocation($source, $conditional)
                                ),
                                $source->getSuppressedIssues()
                            )) {
                                // fall through
                            }
                        } else {
                            if (IssueBuffer::accepts(
                                new TypeDoesNotContainType(
                                    $var_type . ' does not contain false',
                                    new CodeLocation($source, $conditional)
                                ),
                                $source->getSuppressedIssues()
                            )) {
                                // fall through
                            }
                        }
                    }
                }
            }

            return $if_types;
        }

        if ($empty_array_position !== null) {
            if ($empty_array_position === self::ASSIGNMENT_TO_RIGHT) {
                $base_conditional = $conditional->left;
            } elseif ($empty_array_position === self::ASSIGNMENT_TO_LEFT) {
                $base_conditional = $conditional->right;
            } else {
                throw new \UnexpectedValueException('$empty_array_position value');
            }

            $var_name = ExpressionAnalyzer::getArrayVarId(
                $base_conditional,
                $this_class_name,
                $source
            );

            if ($var_name) {
                if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\Identical) {
                    $if_types[$var_name] = [['!non-empty-countable']];
                } else {
                    $if_types[$var_name] = [['falsy']];
                }
            }

            if ($codebase
                && $source instanceof StatementsAnalyzer
                && ($var_type = $source->node_data->getType($base_conditional))
                && $conditional instanceof PhpParser\Node\Expr\BinaryOp\Identical
            ) {
                $null_type = Type::getEmptyArray();

                if (!TypeAnalyzer::isContainedBy(
                    $codebase,
                    $var_type,
                    $null_type
                ) && !TypeAnalyzer::isContainedBy(
                    $codebase,
                    $null_type,
                    $var_type
                )) {
                    if ($var_type->from_docblock) {
                        if (IssueBuffer::accepts(
                            new DocblockTypeContradiction(
                                $var_type . ' does not contain an empty array',
                                new CodeLocation($source, $conditional)
                            ),
                            $source->getSuppressedIssues()
                        )) {
                            // fall through
                        }
                    } else {
                        if (IssueBuffer::accepts(
                            new TypeDoesNotContainType(
                                $var_type . ' does not contain empty array',
                                new CodeLocation($source, $conditional)
                            ),
                            $source->getSuppressedIssues()
                        )) {
                            // fall through
                        }
                    }
                }
            }

            return $if_types;
        }

        if ($gettype_position) {
            if ($gettype_position === self::ASSIGNMENT_TO_RIGHT) {
                $string_expr = $conditional->left;
                $gettype_expr = $conditional->right;
            } elseif ($gettype_position === self::ASSIGNMENT_TO_LEFT) {
                $string_expr = $conditional->right;
                $gettype_expr = $conditional->left;
            } else {
                throw new \UnexpectedValueException('$gettype_position value');
            }

            /** @var PhpParser\Node\Expr\FuncCall $gettype_expr */
            $var_name = ExpressionAnalyzer::getArrayVarId(
                $gettype_expr->args[0]->value,
                $this_class_name,
                $source
            );

            /** @var PhpParser\Node\Scalar\String_ $string_expr */
            $var_type = $string_expr->value;

            if (!isset(ClassLikeAnalyzer::GETTYPE_TYPES[$var_type])) {
                if (IssueBuffer::accepts(
                    new UnevaluatedCode(
                        'gettype cannot return this value',
                        new CodeLocation($source, $string_expr)
                    )
                )) {
                    // fall through
                }
            } else {
                if ($var_name && $var_type) {
                    $if_types[$var_name] = [[$var_type]];
                }
            }

            return $if_types;
        }

        if ($count_equality_position) {
            if ($count_equality_position === self::ASSIGNMENT_TO_RIGHT) {
                $count_expr = $conditional->left;
            } elseif ($count_equality_position === self::ASSIGNMENT_TO_LEFT) {
                $count_expr = $conditional->right;
            } else {
                throw new \UnexpectedValueException('$count_equality_position value');
            }

            /** @var PhpParser\Node\Expr\FuncCall $count_expr */
            $var_name = ExpressionAnalyzer::getArrayVarId(
                $count_expr->args[0]->value,
                $this_class_name,
                $source
            );

            if ($var_name) {
                if ($min_count) {
                    $if_types[$var_name] = [['=has-at-least-' . $min_count]];
                } else {
                    $if_types[$var_name] = [['=non-empty-countable']];
                }
            }

            return $if_types;
        }

        if (!$source instanceof StatementsAnalyzer) {
            return [];
        }

        $getclass_position = self::hasGetClassCheck($conditional, $source);

        if ($getclass_position) {
            if ($getclass_position === self::ASSIGNMENT_TO_RIGHT) {
                $whichclass_expr = $conditional->left;
                $getclass_expr = $conditional->right;
            } elseif ($getclass_position === self::ASSIGNMENT_TO_LEFT) {
                $whichclass_expr = $conditional->right;
                $getclass_expr = $conditional->left;
            } else {
                throw new \UnexpectedValueException('$getclass_position value');
            }

            if ($getclass_expr instanceof PhpParser\Node\Expr\FuncCall) {
                $var_name = ExpressionAnalyzer::getArrayVarId(
                    $getclass_expr->args[0]->value,
                    $this_class_name,
                    $source
                );
            } else {
                $var_name = '$this';
            }

            if ($whichclass_expr instanceof PhpParser\Node\Expr\ClassConstFetch
                && $whichclass_expr->class instanceof PhpParser\Node\Name
            ) {
                $var_type = ClassLikeAnalyzer::getFQCLNFromNameObject(
                    $whichclass_expr->class,
                    $source->getAliases()
                );

                if ($var_type === 'self') {
                    $var_type = $this_class_name;
                } elseif ($var_type === 'parent' || $var_type === 'static') {
                    $var_type = null;
                }

                if ($var_type) {
                    if (ClassLikeAnalyzer::checkFullyQualifiedClassLikeName(
                        $source,
                        $var_type,
                        new CodeLocation($source, $whichclass_expr),
                        $source->getSuppressedIssues(),
                        false
                    ) === false
                    ) {
                        return $if_types;
                    }
                }

                if ($var_name && $var_type) {
                    $if_types[$var_name] = [['=getclass-' . $var_type]];
                }
            } else {
                $type = $source->node_data->getType($whichclass_expr);

                if ($type && $var_name) {
                    foreach ($type->getAtomicTypes() as $type_part) {
                        if ($type_part instanceof Type\Atomic\TTemplateParamClass) {
                            $if_types[$var_name] = [['=' . $type_part->param_name]];
                        }
                    }
                }
            }

            return $if_types;
        }

        $typed_value_position = self::hasTypedValueComparison($conditional, $source);

        if ($typed_value_position) {
            if ($typed_value_position === self::ASSIGNMENT_TO_RIGHT) {
                /** @var PhpParser\Node\Expr $conditional->right */
                $var_name = ExpressionAnalyzer::getArrayVarId(
                    $conditional->left,
                    $this_class_name,
                    $source
                );

                $other_type = $source->node_data->getType($conditional->left);
                $var_type = $source->node_data->getType($conditional->right);
            } elseif ($typed_value_position === self::ASSIGNMENT_TO_LEFT) {
                /** @var PhpParser\Node\Expr $conditional->left */
                $var_name = ExpressionAnalyzer::getArrayVarId(
                    $conditional->right,
                    $this_class_name,
                    $source
                );

                $var_type = $source->node_data->getType($conditional->left);
                $other_type = $source->node_data->getType($conditional->right);
            } else {
                throw new \UnexpectedValueException('$typed_value_position value');
            }

            if ($var_name && $var_type) {
                $identical = $conditional instanceof PhpParser\Node\Expr\BinaryOp\Identical
                    || ($other_type
                        && (($var_type->isString() && $other_type->isString())
                            || ($var_type->isInt() && $other_type->isInt())
                            || ($var_type->isFloat() && $other_type->isFloat())
                        )
                    );

                if ($identical) {
                    $if_types[$var_name] = [['=' . $var_type->getAssertionString()]];
                } else {
                    $if_types[$var_name] = [['~' . $var_type->getAssertionString()]];
                }
            }

            if ($codebase
                && $other_type
                && $var_type
                && $conditional instanceof PhpParser\Node\Expr\BinaryOp\Identical
            ) {
                $parent_source = $source->getSource();

                if ($parent_source->getSource() instanceof \Psalm\Internal\Analyzer\TraitAnalyzer
                    && (($var_type->isSingleStringLiteral()
                            && $var_type->getSingleStringLiteral()->value === $this_class_name)
                        || ($other_type->isSingleStringLiteral()
                            && $other_type->getSingleStringLiteral()->value === $this_class_name))
                ) {
                    // do nothing
                } elseif (!TypeAnalyzer::canExpressionTypesBeIdentical(
                    $codebase,
                    $other_type,
                    $var_type
                )) {
                    if ($var_type->from_docblock || $other_type->from_docblock) {
                        if (IssueBuffer::accepts(
                            new DocblockTypeContradiction(
                                $var_type->getId() . ' does not contain ' . $other_type->getId(),
                                new CodeLocation($source, $conditional)
                            ),
                            $source->getSuppressedIssues()
                        )) {
                            // fall through
                        }
                    } else {
                        if (IssueBuffer::accepts(
                            new TypeDoesNotContainType(
                                $var_type->getId() . ' cannot be identical to ' . $other_type->getId(),
                                new CodeLocation($source, $conditional)
                            ),
                            $source->getSuppressedIssues()
                        )) {
                            // fall through
                        }
                    }
                }
            }

            return $if_types;
        }

        $var_type = $source->node_data->getType($conditional->left);
        $other_type = $source->node_data->getType($conditional->right);

        if ($codebase
            && $var_type
            && $other_type
            && $conditional instanceof PhpParser\Node\Expr\BinaryOp\Identical
        ) {
            if (!TypeAnalyzer::canExpressionTypesBeIdentical($codebase, $var_type, $other_type)) {
                if (IssueBuffer::accepts(
                    new TypeDoesNotContainType(
                        $var_type->getId() . ' cannot be identical to ' . $other_type->getId(),
                        new CodeLocation($source, $conditional)
                    ),
                    $source->getSuppressedIssues()
                )) {
                    // fall through
                }
            }
        }

        return [];
    }

    /**
     * @param PhpParser\Node\Expr\BinaryOp\NotIdentical|PhpParser\Node\Expr\BinaryOp\NotEqual $conditional
     * @param string|null $this_class_name
     *
     * @return array<string, non-empty-list<non-empty-list<string>>>
     */
    private static function scrapeInequalityAssertions(
        PhpParser\Node\Expr\BinaryOp $conditional,
        $this_class_name,
        FileSource $source,
        Codebase $codebase = null,
        bool $inside_negation = false,
        bool $cache = true
    ) {
        $if_types = [];

        $null_position = self::hasNullVariable($conditional);
        $false_position = self::hasFalseVariable($conditional);
        $true_position = self::hasTrueVariable($conditional);
        $empty_array_position = self::hasEmptyArrayVariable($conditional);
        $gettype_position = self::hasGetTypeCheck($conditional);

        if ($null_position !== null) {
            if ($null_position === self::ASSIGNMENT_TO_RIGHT) {
                $base_conditional = $conditional->left;
            } elseif ($null_position === self::ASSIGNMENT_TO_LEFT) {
                $base_conditional = $conditional->right;
            } else {
                throw new \UnexpectedValueException('Bad null variable position');
            }

            $var_name = ExpressionAnalyzer::getArrayVarId(
                $base_conditional,
                $this_class_name,
                $source
            );

            if ($var_name) {
                if ($base_conditional instanceof PhpParser\Node\Expr\Assign) {
                    $var_name = '=' . $var_name;
                }

                if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\NotIdentical) {
                    $if_types[$var_name] = [['!null']];
                } else {
                    $if_types[$var_name] = [['!falsy']];
                }
            }

            if ($codebase
                && $source instanceof StatementsAnalyzer
                && ($var_type = $source->node_data->getType($base_conditional))
            ) {
                if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\NotIdentical) {
                    $null_type = Type::getNull();

                    if (!TypeAnalyzer::isContainedBy(
                        $codebase,
                        $var_type,
                        $null_type
                    ) && !TypeAnalyzer::isContainedBy(
                        $codebase,
                        $null_type,
                        $var_type
                    )) {
                        if ($var_type->from_docblock) {
                            if (IssueBuffer::accepts(
                                new RedundantConditionGivenDocblockType(
                                    'Docblock-asserted type ' . $var_type . ' can never contain null',
                                    new CodeLocation($source, $conditional)
                                ),
                                $source->getSuppressedIssues()
                            )) {
                                // fall through
                            }
                        } else {
                            if (IssueBuffer::accepts(
                                new RedundantCondition(
                                    $var_type . ' can never contain null',
                                    new CodeLocation($source, $conditional)
                                ),
                                $source->getSuppressedIssues()
                            )) {
                                // fall through
                            }
                        }
                    }
                }
            }

            return $if_types;
        }

        if ($false_position) {
            if ($false_position === self::ASSIGNMENT_TO_RIGHT) {
                $base_conditional = $conditional->left;
            } elseif ($false_position === self::ASSIGNMENT_TO_LEFT) {
                $base_conditional = $conditional->right;
            } else {
                throw new \UnexpectedValueException('Bad false variable position');
            }

            $var_name = ExpressionAnalyzer::getArrayVarId(
                $base_conditional,
                $this_class_name,
                $source
            );

            if ($var_name) {
                if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\NotIdentical) {
                    $if_types[$var_name] = [['!false']];
                } else {
                    $if_types[$var_name] = [['!falsy']];
                }
            } else {
                $base_assertions = null;

                if ($source instanceof StatementsAnalyzer && $cache) {
                    $base_assertions = $source->node_data->getAssertions($base_conditional);
                }

                if ($base_assertions === null) {
                    $base_assertions = self::scrapeAssertions(
                        $base_conditional,
                        $this_class_name,
                        $source,
                        $codebase,
                        $inside_negation,
                        $cache
                    );

                    if ($source instanceof StatementsAnalyzer && $cache) {
                        $source->node_data->setAssertions($base_conditional, $base_assertions);
                    }
                }

                if ($base_assertions === null) {
                    throw new \UnexpectedValueException('Assertions should be set');
                }

                $notif_types = $base_assertions;

                if (count($notif_types) === 1) {
                    $if_types = \Psalm\Type\Algebra::negateTypes($notif_types);
                }
            }

            if ($codebase
                && $source instanceof StatementsAnalyzer
                && ($var_type = $source->node_data->getType($base_conditional))
            ) {
                if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\NotIdentical) {
                    $false_type = Type::getFalse();

                    if (!TypeAnalyzer::isContainedBy(
                        $codebase,
                        $var_type,
                        $false_type
                    ) && !TypeAnalyzer::isContainedBy(
                        $codebase,
                        $false_type,
                        $var_type
                    )) {
                        if ($var_type->from_docblock) {
                            if (IssueBuffer::accepts(
                                new RedundantConditionGivenDocblockType(
                                    'Docblock-asserted type ' . $var_type . ' can never contain false',
                                    new CodeLocation($source, $conditional)
                                ),
                                $source->getSuppressedIssues()
                            )) {
                                // fall through
                            }
                        } else {
                            if (IssueBuffer::accepts(
                                new RedundantCondition(
                                    $var_type . ' can never contain false',
                                    new CodeLocation($source, $conditional)
                                ),
                                $source->getSuppressedIssues()
                            )) {
                                // fall through
                            }
                        }
                    }
                }
            }

            return $if_types;
        }

        if ($true_position) {
            if ($true_position === self::ASSIGNMENT_TO_RIGHT) {
                $base_conditional = $conditional->left;
            } elseif ($true_position === self::ASSIGNMENT_TO_LEFT) {
                $base_conditional = $conditional->right;
            } else {
                throw new \UnexpectedValueException('Bad null variable position');
            }

            if ($base_conditional instanceof PhpParser\Node\Expr\FuncCall) {
                $if_types = self::processFunctionCall(
                    $base_conditional,
                    $this_class_name,
                    $source,
                    true
                );
            } else {
                $var_name = ExpressionAnalyzer::getArrayVarId(
                    $base_conditional,
                    $this_class_name,
                    $source
                );

                if ($var_name) {
                    if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\NotIdentical) {
                        $if_types[$var_name] = [['!true']];
                    } else {
                        $if_types[$var_name] = [['falsy']];
                    }
                } else {
                    $base_assertions = null;

                    if ($source instanceof StatementsAnalyzer && $cache) {
                        $base_assertions = $source->node_data->getAssertions($base_conditional);
                    }

                    if ($base_assertions === null) {
                        $base_assertions = self::scrapeAssertions(
                            $base_conditional,
                            $this_class_name,
                            $source,
                            $codebase,
                            $inside_negation,
                            $cache
                        );

                        if ($source instanceof StatementsAnalyzer && $cache) {
                            $source->node_data->setAssertions($base_conditional, $base_assertions);
                        }
                    }

                    if ($base_assertions === null) {
                        throw new \UnexpectedValueException('Assertions should be set');
                    }

                    $notif_types = $base_assertions;

                    if (count($notif_types) === 1) {
                        $if_types = \Psalm\Type\Algebra::negateTypes($notif_types);
                    }
                }
            }

            if ($codebase
                && $source instanceof StatementsAnalyzer
                && ($var_type = $source->node_data->getType($base_conditional))
            ) {
                if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\NotIdentical) {
                    $true_type = Type::getTrue();

                    if (!TypeAnalyzer::isContainedBy(
                        $codebase,
                        $var_type,
                        $true_type
                    ) && !TypeAnalyzer::isContainedBy(
                        $codebase,
                        $true_type,
                        $var_type
                    )) {
                        if ($var_type->from_docblock) {
                            if (IssueBuffer::accepts(
                                new RedundantConditionGivenDocblockType(
                                    'Docblock-asserted type ' . $var_type . ' can never contain true',
                                    new CodeLocation($source, $conditional)
                                ),
                                $source->getSuppressedIssues()
                            )) {
                                // fall through
                            }
                        } else {
                            if (IssueBuffer::accepts(
                                new RedundantCondition(
                                    $var_type . ' can never contain ' . $true_type,
                                    new CodeLocation($source, $conditional)
                                ),
                                $source->getSuppressedIssues()
                            )) {
                                // fall through
                            }
                        }
                    }
                }
            }

            return $if_types;
        }

        if ($empty_array_position !== null) {
            if ($empty_array_position === self::ASSIGNMENT_TO_RIGHT) {
                $base_conditional = $conditional->left;
            } elseif ($empty_array_position === self::ASSIGNMENT_TO_LEFT) {
                $base_conditional = $conditional->right;
            } else {
                throw new \UnexpectedValueException('Bad empty array variable position');
            }

            $var_name = ExpressionAnalyzer::getArrayVarId(
                $base_conditional,
                $this_class_name,
                $source
            );

            if ($var_name) {
                if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\NotIdentical) {
                    $if_types[$var_name] = [['non-empty-countable']];
                } else {
                    $if_types[$var_name] = [['!falsy']];
                }
            }

            if ($codebase
                && $source instanceof StatementsAnalyzer
                && ($var_type = $source->node_data->getType($base_conditional))
            ) {
                if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\NotIdentical) {
                    $empty_array_type = Type::getEmptyArray();

                    if (!TypeAnalyzer::isContainedBy(
                        $codebase,
                        $var_type,
                        $empty_array_type
                    ) && !TypeAnalyzer::isContainedBy(
                        $codebase,
                        $empty_array_type,
                        $var_type
                    )) {
                        if ($var_type->from_docblock) {
                            if (IssueBuffer::accepts(
                                new RedundantConditionGivenDocblockType(
                                    'Docblock-asserted type ' . $var_type . ' can never contain null',
                                    new CodeLocation($source, $conditional)
                                ),
                                $source->getSuppressedIssues()
                            )) {
                                // fall through
                            }
                        } else {
                            if (IssueBuffer::accepts(
                                new RedundantCondition(
                                    $var_type . ' can never contain null',
                                    new CodeLocation($source, $conditional)
                                ),
                                $source->getSuppressedIssues()
                            )) {
                                // fall through
                            }
                        }
                    }
                }
            }

            return $if_types;
        }

        if ($gettype_position) {
            if ($gettype_position === self::ASSIGNMENT_TO_RIGHT) {
                $whichclass_expr = $conditional->left;
                $gettype_expr = $conditional->right;
            } elseif ($gettype_position === self::ASSIGNMENT_TO_LEFT) {
                $whichclass_expr = $conditional->right;
                $gettype_expr = $conditional->left;
            } else {
                throw new \UnexpectedValueException('$gettype_position value');
            }

            /** @var PhpParser\Node\Expr\FuncCall $gettype_expr */
            $var_name = ExpressionAnalyzer::getArrayVarId(
                $gettype_expr->args[0]->value,
                $this_class_name,
                $source
            );

            if ($whichclass_expr instanceof PhpParser\Node\Scalar\String_) {
                $var_type = $whichclass_expr->value;
            } elseif ($whichclass_expr instanceof PhpParser\Node\Expr\ClassConstFetch
                && $whichclass_expr->class instanceof PhpParser\Node\Name
            ) {
                $var_type = ClassLikeAnalyzer::getFQCLNFromNameObject(
                    $whichclass_expr->class,
                    $source->getAliases()
                );
            } else {
                throw new \UnexpectedValueException('Shouldn’t get here');
            }

            if (!isset(ClassLikeAnalyzer::GETTYPE_TYPES[$var_type])) {
                if (IssueBuffer::accepts(
                    new UnevaluatedCode(
                        'gettype cannot return this value',
                        new CodeLocation($source, $whichclass_expr)
                    )
                )) {
                    // fall through
                }
            } else {
                if ($var_name && $var_type) {
                    $if_types[$var_name] = [['!' . $var_type]];
                }
            }

            return $if_types;
        }

        if (!$source instanceof StatementsAnalyzer) {
            return [];
        }

        $getclass_position = self::hasGetClassCheck($conditional, $source);
        $typed_value_position = self::hasTypedValueComparison($conditional, $source);

        if ($getclass_position) {
            if ($getclass_position === self::ASSIGNMENT_TO_RIGHT) {
                $whichclass_expr = $conditional->left;
                $getclass_expr = $conditional->right;
            } elseif ($getclass_position === self::ASSIGNMENT_TO_LEFT) {
                $whichclass_expr = $conditional->right;
                $getclass_expr = $conditional->left;
            } else {
                throw new \UnexpectedValueException('$getclass_position value');
            }

            if ($getclass_expr instanceof PhpParser\Node\Expr\FuncCall) {
                $var_name = ExpressionAnalyzer::getArrayVarId(
                    $getclass_expr->args[0]->value,
                    $this_class_name,
                    $source
                );
            } else {
                $var_name = '$this';
            }

            if ($whichclass_expr instanceof PhpParser\Node\Scalar\String_) {
                $var_type = $whichclass_expr->value;
            } elseif ($whichclass_expr instanceof PhpParser\Node\Expr\ClassConstFetch
                && $whichclass_expr->class instanceof PhpParser\Node\Name
            ) {
                $var_type = ClassLikeAnalyzer::getFQCLNFromNameObject(
                    $whichclass_expr->class,
                    $source->getAliases()
                );

                if ($var_type === 'self') {
                    $var_type = $this_class_name;
                } elseif ($var_type === 'parent' || $var_type === 'static') {
                    $var_type = null;
                }
            } else {
                $type = $source->node_data->getType($whichclass_expr);

                if ($type && $var_name) {
                    foreach ($type->getAtomicTypes() as $type_part) {
                        if ($type_part instanceof Type\Atomic\TTemplateParamClass) {
                            $if_types[$var_name] = [['!=' . $type_part->param_name]];
                        }
                    }
                }

                return $if_types;
            }

            if ($var_type
                && ClassLikeAnalyzer::checkFullyQualifiedClassLikeName(
                    $source,
                    $var_type,
                    new CodeLocation($source, $whichclass_expr),
                    $source->getSuppressedIssues(),
                    false
                ) === false
            ) {
                // fall through
            } else {
                if ($var_name && $var_type) {
                    $if_types[$var_name] = [['!=getclass-' . $var_type]];
                }
            }

            return $if_types;
        }

        if ($typed_value_position) {
            if ($typed_value_position === self::ASSIGNMENT_TO_RIGHT) {
                /** @var PhpParser\Node\Expr $conditional->right */
                $var_name = ExpressionAnalyzer::getArrayVarId(
                    $conditional->left,
                    $this_class_name,
                    $source
                );

                $other_type = $source->node_data->getType($conditional->left);
                $var_type = $source->node_data->getType($conditional->right);
            } elseif ($typed_value_position === self::ASSIGNMENT_TO_LEFT) {
                /** @var PhpParser\Node\Expr $conditional->left */
                $var_name = ExpressionAnalyzer::getArrayVarId(
                    $conditional->right,
                    $this_class_name,
                    $source
                );

                $var_type = $source->node_data->getType($conditional->left);
                $other_type = $source->node_data->getType($conditional->right);
            } else {
                throw new \UnexpectedValueException('$typed_value_position value');
            }

            if ($var_type) {
                if ($var_name) {
                    $not_identical = $conditional instanceof PhpParser\Node\Expr\BinaryOp\NotIdentical
                        || ($other_type
                            && (($var_type->isString() && $other_type->isString())
                                || ($var_type->isInt() && $other_type->isInt())
                                || ($var_type->isFloat() && $other_type->isFloat())
                            )
                        );

                    if ($not_identical) {
                        $if_types[$var_name] = [['!=' . $var_type->getAssertionString()]];
                    } else {
                        $if_types[$var_name] = [['!~' . $var_type->getAssertionString()]];
                    }
                }

                if ($codebase
                    && $other_type
                    && $conditional instanceof PhpParser\Node\Expr\BinaryOp\NotIdentical
                ) {
                    $parent_source = $source->getSource();

                    if ($parent_source->getSource() instanceof \Psalm\Internal\Analyzer\TraitAnalyzer
                        && (($var_type->isSingleStringLiteral()
                                && $var_type->getSingleStringLiteral()->value === $this_class_name)
                            || ($other_type->isSingleStringLiteral()
                                && $other_type->getSingleStringLiteral()->value === $this_class_name))
                    ) {
                        // do nothing
                    } elseif (!TypeAnalyzer::canExpressionTypesBeIdentical(
                        $codebase,
                        $other_type,
                        $var_type
                    )) {
                        if ($var_type->from_docblock || $other_type->from_docblock) {
                            if (IssueBuffer::accepts(
                                new DocblockTypeContradiction(
                                    $var_type . ' can never contain ' . $other_type,
                                    new CodeLocation($source, $conditional)
                                ),
                                $source->getSuppressedIssues()
                            )) {
                                // fall through
                            }
                        } else {
                            if (IssueBuffer::accepts(
                                new RedundantCondition(
                                    $var_type->getId() . ' can never contain ' . $other_type->getId(),
                                    new CodeLocation($source, $conditional)
                                ),
                                $source->getSuppressedIssues()
                            )) {
                                // fall through
                            }
                        }
                    }
                }
            }

            return $if_types;
        }

        return [];
    }

    /**
     * @param  PhpParser\Node\Expr\FuncCall $expr
     * @param  string|null                  $this_class_name
     * @param  FileSource                   $source
     * @param  bool                         $negate
     *
     * @return array<string, non-empty-list<non-empty-list<string>>>
     */
    public static function processFunctionCall(
        PhpParser\Node\Expr\FuncCall $expr,
        $this_class_name,
        FileSource $source,
        $negate = false
    ) {
        $prefix = $negate ? '!' : '';

        $first_var_name = isset($expr->args[0]->value)
            ? ExpressionAnalyzer::getArrayVarId(
                $expr->args[0]->value,
                $this_class_name,
                $source
            )
            : null;

        $if_types = [];

        if (self::hasNullCheck($expr)) {
            if ($first_var_name) {
                $if_types[$first_var_name] = [[$prefix . 'null']];
            }
        } elseif ($source instanceof StatementsAnalyzer && self::hasIsACheck($expr, $source)) {
            if ($expr->args[0]->value instanceof PhpParser\Node\Expr\ClassConstFetch
                && $expr->args[0]->value->name instanceof PhpParser\Node\Identifier
                && strtolower($expr->args[0]->value->name->name) === 'class'
                && $expr->args[0]->value->class instanceof PhpParser\Node\Name
                && count($expr->args[0]->value->class->parts) === 1
                && strtolower($expr->args[0]->value->class->parts[0]) === 'static'
            ) {
                $first_var_name = '$this';
            }

            if ($first_var_name) {
                $first_arg = $expr->args[0]->value;
                $second_arg = $expr->args[1]->value;
                $third_arg = isset($expr->args[2]->value) ? $expr->args[2]->value : null;

                if ($third_arg instanceof PhpParser\Node\Expr\ConstFetch) {
                    if (!in_array(strtolower($third_arg->name->parts[0]), ['true', 'false'])) {
                        return $if_types;
                    }

                    $third_arg_value = strtolower($third_arg->name->parts[0]);
                } else {
                    $third_arg_value = $expr->name instanceof PhpParser\Node\Name
                        && strtolower($expr->name->parts[0]) === 'is_subclass_of'
                        ? 'true'
                        : 'false';
                }

                $is_a_prefix = $third_arg_value === 'true' ? 'isa-string-' : 'isa-';

                if ($first_arg
                    && ($first_arg_type = $source->node_data->getType($first_arg))
                    && $first_arg_type->isSingleStringLiteral()
                    && $source->getSource()->getSource() instanceof \Psalm\Internal\Analyzer\TraitAnalyzer
                    && $first_arg_type->getSingleStringLiteral()->value === $this_class_name
                ) {
                    // do nothing
                } else {
                    if ($second_arg instanceof PhpParser\Node\Scalar\String_) {
                        $fq_class_name = $second_arg->value;
                        if ($fq_class_name[0] === '\\') {
                            $fq_class_name = substr($fq_class_name, 1);
                        }

                        $if_types[$first_var_name] = [[$prefix . $is_a_prefix . $fq_class_name]];
                    } elseif ($second_arg instanceof PhpParser\Node\Expr\ClassConstFetch
                        && $second_arg->class instanceof PhpParser\Node\Name
                        && $second_arg->name instanceof PhpParser\Node\Identifier
                        && strtolower($second_arg->name->name) === 'class'
                    ) {
                        $class_node = $second_arg->class;

                        if ($class_node->parts === ['static'] || $class_node->parts === ['self']) {
                            if ($this_class_name) {
                                $if_types[$first_var_name] = [[$prefix . $is_a_prefix . $this_class_name]];
                            }
                        } elseif ($class_node->parts === ['parent']) {
                            // do nothing
                        } else {
                            $if_types[$first_var_name] = [[
                                $prefix . $is_a_prefix
                                    . ClassLikeAnalyzer::getFQCLNFromNameObject(
                                        $class_node,
                                        $source->getAliases()
                                    )
                            ]];
                        }
                    } elseif (($second_arg_type = $source->node_data->getType($second_arg))
                        && $second_arg_type->hasString()
                    ) {
                        $vals = [];

                        foreach ($second_arg_type->getAtomicTypes() as $second_arg_atomic_type) {
                            if ($second_arg_atomic_type instanceof Type\Atomic\TTemplateParamClass) {
                                $vals[] = [$prefix . $is_a_prefix . $second_arg_atomic_type->param_name];
                            }
                        }

                        if ($vals) {
                            $if_types[$first_var_name] = $vals;
                        }
                    }
                }
            }
        } elseif (self::hasArrayCheck($expr)) {
            if ($first_var_name) {
                $if_types[$first_var_name] = [[$prefix . 'array']];
            }
        } elseif (self::hasBoolCheck($expr)) {
            if ($first_var_name) {
                $if_types[$first_var_name] = [[$prefix . 'bool']];
            }
        } elseif (self::hasStringCheck($expr)) {
            if ($first_var_name) {
                $if_types[$first_var_name] = [[$prefix . 'string']];
            }
        } elseif (self::hasObjectCheck($expr)) {
            if ($first_var_name) {
                $if_types[$first_var_name] = [[$prefix . 'object']];
            }
        } elseif (self::hasNumericCheck($expr)) {
            if ($first_var_name) {
                $if_types[$first_var_name] = [[$prefix . 'numeric']];
            }
        } elseif (self::hasIntCheck($expr)) {
            if ($first_var_name) {
                $if_types[$first_var_name] = [[$prefix . 'int']];
            }
        } elseif (self::hasFloatCheck($expr)) {
            if ($first_var_name) {
                $if_types[$first_var_name] = [[$prefix . 'float']];
            }
        } elseif (self::hasResourceCheck($expr)) {
            if ($first_var_name) {
                $if_types[$first_var_name] = [[$prefix . 'resource']];
            }
        } elseif (self::hasScalarCheck($expr)) {
            if ($first_var_name) {
                $if_types[$first_var_name] = [[$prefix . 'scalar']];
            }
        } elseif (self::hasCallableCheck($expr)) {
            if ($first_var_name) {
                $if_types[$first_var_name] = [[$prefix . 'callable']];
            }
        } elseif (self::hasIterableCheck($expr)) {
            if ($first_var_name) {
                $if_types[$first_var_name] = [[$prefix . 'iterable']];
            }
        } elseif (self::hasCountableCheck($expr)) {
            if ($first_var_name) {
                $if_types[$first_var_name] = [[$prefix . 'countable']];
            }
        } elseif ($class_exists_check_type = self::hasClassExistsCheck($expr)) {
            if ($first_var_name) {
                if ($class_exists_check_type === 2) {
                    $if_types[$first_var_name] = [[$prefix . 'class-string']];
                } elseif (!$prefix) {
                    $if_types[$first_var_name] = [['=class-string']];
                }
            }
        } elseif ($class_exists_check_type = self::hasTraitExistsCheck($expr)) {
            if ($first_var_name) {
                if ($class_exists_check_type === 2) {
                    $if_types[$first_var_name] = [[$prefix . 'trait-string']];
                } elseif (!$prefix) {
                    $if_types[$first_var_name] = [['=trait-string']];
                }
            }
        } elseif (self::hasInterfaceExistsCheck($expr)) {
            if ($first_var_name) {
                $if_types[$first_var_name] = [[$prefix . 'interface-string']];
            }
        } elseif (self::hasFunctionExistsCheck($expr)) {
            if ($first_var_name) {
                $if_types[$first_var_name] = [[$prefix . 'callable-string']];
            }
        } elseif ($expr->name instanceof PhpParser\Node\Name
            && strtolower($expr->name->parts[0]) === 'method_exists'
            && isset($expr->args[1])
            && $expr->args[1]->value instanceof PhpParser\Node\Scalar\String_
        ) {
            if ($first_var_name) {
                $if_types[$first_var_name] = [[$prefix . 'hasmethod-' . $expr->args[1]->value->value]];
            }
        } elseif (self::hasInArrayCheck($expr) && $source instanceof StatementsAnalyzer) {
            if ($first_var_name
                && ($second_arg_type = $source->node_data->getType($expr->args[1]->value))
            ) {
                foreach ($second_arg_type->getAtomicTypes() as $atomic_type) {
                    if ($atomic_type instanceof Type\Atomic\TArray
                        || $atomic_type instanceof Type\Atomic\ObjectLike
                    ) {
                        if ($atomic_type instanceof Type\Atomic\ObjectLike) {
                            $atomic_type = $atomic_type->getGenericArrayType();
                        }

                        $array_literal_types = array_merge(
                            $atomic_type->type_params[1]->getLiteralStrings(),
                            $atomic_type->type_params[1]->getLiteralInts(),
                            $atomic_type->type_params[1]->getLiteralFloats()
                        );

                        if ($array_literal_types
                            && count($atomic_type->type_params[1]->getAtomicTypes())
                        ) {
                            $literal_assertions = [];

                            foreach ($array_literal_types as $array_literal_type) {
                                $literal_assertions[] = '=' . $array_literal_type->getId();
                            }

                            if ($negate) {
                                $if_types = \Psalm\Type\Algebra::negateTypes([
                                    $first_var_name => [$literal_assertions]
                                ]);
                            } else {
                                $if_types[$first_var_name] = [$literal_assertions];
                            }
                        }
                    }
                }
            }
        } elseif (self::hasArrayKeyExistsCheck($expr)) {
            $array_root = isset($expr->args[1]->value)
                ? ExpressionAnalyzer::getArrayVarId(
                    $expr->args[1]->value,
                    $this_class_name,
                    $source
                )
                : null;

            if ($first_var_name === null && isset($expr->args[0])) {
                $first_arg = $expr->args[0];

                if ($first_arg->value instanceof PhpParser\Node\Scalar\String_) {
                    $first_var_name = '\'' . $first_arg->value->value . '\'';
                } elseif ($first_arg->value instanceof PhpParser\Node\Scalar\LNumber) {
                    $first_var_name = (string) $first_arg->value->value;
                }
            }

            if ($first_var_name !== null
                && $array_root
                && !strpos($first_var_name, '->')
                && !strpos($first_var_name, '[')
            ) {
                $if_types[$array_root . '[' . $first_var_name . ']'] = [[$prefix . 'array-key-exists']];
            }
        } elseif (self::hasNonEmptyCountCheck($expr)) {
            if ($first_var_name) {
                $if_types[$first_var_name] = [[$prefix . 'non-empty-countable']];
            }
        } else {
            $if_types = self::processCustomAssertion($expr, $this_class_name, $source, $negate);
        }

        return $if_types;
    }

    /**
     * @param  PhpParser\Node\Expr\FuncCall|PhpParser\Node\Expr\MethodCall|PhpParser\Node\Expr\StaticCall $expr
     * @param  string|null  $this_class_name
     * @param  FileSource   $source
     * @param  bool         $negate
     *
     * @return array<string, non-empty-list<non-empty-list<string>>>
     */
    protected static function processCustomAssertion(
        $expr,
        $this_class_name,
        FileSource $source,
        $negate = false
    ) {
        if (!$source instanceof StatementsAnalyzer) {
            return [];
        }

        $if_true_assertions = $source->node_data->getIfTrueAssertions($expr);
        $if_false_assertions = $source->node_data->getIfFalseAssertions($expr);

        if ($if_true_assertions === null && $if_false_assertions === null) {
            return [];
        }

        $prefix = $negate ? '!' : '';

        $first_var_name = isset($expr->args[0]->value)
            ? ExpressionAnalyzer::getArrayVarId(
                $expr->args[0]->value,
                $this_class_name,
                $source
            )
            : null;

        $if_types = [];

        if ($if_true_assertions) {
            foreach ($if_true_assertions as $assertion) {
                if (is_int($assertion->var_id) && isset($expr->args[$assertion->var_id])) {
                    if ($assertion->var_id === 0) {
                        $var_name = $first_var_name;
                    } else {
                        $var_name = ExpressionAnalyzer::getArrayVarId(
                            $expr->args[$assertion->var_id]->value,
                            $this_class_name,
                            $source
                        );
                    }

                    if ($var_name) {
                        if ($prefix === $assertion->rule[0][0][0]) {
                            $if_types[$var_name] = [[substr($assertion->rule[0][0], 1)]];
                        } else {
                            $if_types[$var_name] = [[$prefix . $assertion->rule[0][0]]];
                        }
                    }
                } elseif ($assertion->var_id === '$this' && $expr instanceof PhpParser\Node\Expr\MethodCall) {
                    $var_id = ExpressionAnalyzer::getArrayVarId(
                        $expr->var,
                        $this_class_name,
                        $source
                    );

                    if ($var_id) {
                        if ($prefix === $assertion->rule[0][0][0]) {
                            $if_types[$var_id] = [[substr($assertion->rule[0][0], 1)]];
                        } else {
                            $if_types[$var_id] = [[$prefix . $assertion->rule[0][0]]];
                        }
                    }
                } elseif (\is_string($assertion->var_id)
                    && $expr instanceof PhpParser\Node\Expr\MethodCall
                ) {
                    if ($prefix === $assertion->rule[0][0][0]) {
                        $if_types[$assertion->var_id] = [[substr($assertion->rule[0][0], 1)]];
                    } else {
                        $if_types[$assertion->var_id] = [[$prefix . $assertion->rule[0][0]]];
                    }
                }
            }
        }

        if ($if_false_assertions) {
            $negated_prefix = !$negate ? '!' : '';

            foreach ($if_false_assertions as $assertion) {
                if (is_int($assertion->var_id) && isset($expr->args[$assertion->var_id])) {
                    if ($assertion->var_id === 0) {
                        $var_name = $first_var_name;
                    } else {
                        $var_name = ExpressionAnalyzer::getArrayVarId(
                            $expr->args[$assertion->var_id]->value,
                            $this_class_name,
                            $source
                        );
                    }

                    if ($var_name) {
                        if ($negated_prefix === $assertion->rule[0][0][0]) {
                            $if_types[$var_name] = [[substr($assertion->rule[0][0], 1)]];
                        } else {
                            $if_types[$var_name] = [[$negated_prefix . $assertion->rule[0][0]]];
                        }
                    }
                } elseif ($assertion->var_id === '$this' && $expr instanceof PhpParser\Node\Expr\MethodCall) {
                    $var_id = ExpressionAnalyzer::getArrayVarId(
                        $expr->var,
                        $this_class_name,
                        $source
                    );

                    if ($var_id) {
                        if ($negated_prefix === $assertion->rule[0][0][0]) {
                            $if_types[$var_id] = [[substr($assertion->rule[0][0], 1)]];
                        } else {
                            $if_types[$var_id] = [[$negated_prefix . $assertion->rule[0][0]]];
                        }
                    }
                } elseif (\is_string($assertion->var_id)
                    && $expr instanceof PhpParser\Node\Expr\MethodCall
                ) {
                    if ($prefix === $assertion->rule[0][0][0]) {
                        $if_types[$assertion->var_id] = [[substr($assertion->rule[0][0], 1)]];
                    } else {
                        $if_types[$assertion->var_id] = [[$negated_prefix . $assertion->rule[0][0]]];
                    }
                }
            }
        }

        return $if_types;
    }

    /**
     * @param  PhpParser\Node\Expr\Instanceof_ $stmt
     * @param  string|null                     $this_class_name
     * @param  FileSource                $source
     *
     * @return list<string>
     */
    protected static function getInstanceOfTypes(
        PhpParser\Node\Expr\Instanceof_ $stmt,
        $this_class_name,
        FileSource $source
    ) {
        if ($stmt->class instanceof PhpParser\Node\Name) {
            if (!in_array(strtolower($stmt->class->parts[0]), ['self', 'static', 'parent'], true)) {
                $instanceof_class = ClassLikeAnalyzer::getFQCLNFromNameObject(
                    $stmt->class,
                    $source->getAliases()
                );

                if ($source instanceof StatementsAnalyzer) {
                    $codebase = $source->getCodebase();
                    $instanceof_class = $codebase->classlikes->getUnAliasedName($instanceof_class);
                }

                return [$instanceof_class];
            } elseif ($this_class_name
                && (in_array(strtolower($stmt->class->parts[0]), ['self', 'static'], true))
            ) {
                if ($stmt->class->parts[0] === 'static') {
                    return ['=' . $this_class_name];
                }

                return [$this_class_name];
            }
        } elseif ($source instanceof StatementsAnalyzer) {
            $stmt_class_type = $source->node_data->getType($stmt->class);

            if ($stmt_class_type) {
                $literal_class_strings = [];

                foreach ($stmt_class_type->getAtomicTypes() as $atomic_type) {
                    if ($atomic_type instanceof Type\Atomic\TLiteralClassString) {
                        $literal_class_strings[] = $atomic_type->value;
                    } elseif ($atomic_type instanceof Type\Atomic\TTemplateParamClass) {
                        $literal_class_strings[] = $atomic_type->param_name;
                    }
                }

                return $literal_class_strings;
            }
        }

        return [];
    }

    /**
     * @param   PhpParser\Node\Expr\BinaryOp    $conditional
     *
     * @return  int|null
     */
    protected static function hasNullVariable(PhpParser\Node\Expr\BinaryOp $conditional)
    {
        if ($conditional->right instanceof PhpParser\Node\Expr\ConstFetch
            && strtolower($conditional->right->name->parts[0]) === 'null'
        ) {
            return self::ASSIGNMENT_TO_RIGHT;
        }

        if ($conditional->left instanceof PhpParser\Node\Expr\ConstFetch
            && strtolower($conditional->left->name->parts[0]) === 'null'
        ) {
            return self::ASSIGNMENT_TO_LEFT;
        }

        return null;
    }

    /**
     * @param   PhpParser\Node\Expr\BinaryOp    $conditional
     *
     * @return  int|null
     */
    public static function hasFalseVariable(PhpParser\Node\Expr\BinaryOp $conditional)
    {
        if ($conditional->right instanceof PhpParser\Node\Expr\ConstFetch
            && strtolower($conditional->right->name->parts[0]) === 'false'
        ) {
            return self::ASSIGNMENT_TO_RIGHT;
        }

        if ($conditional->left instanceof PhpParser\Node\Expr\ConstFetch
            && strtolower($conditional->left->name->parts[0]) === 'false'
        ) {
            return self::ASSIGNMENT_TO_LEFT;
        }

        return null;
    }

    /**
     * @param   PhpParser\Node\Expr\BinaryOp    $conditional
     *
     * @return  int|null
     */
    protected static function hasTrueVariable(PhpParser\Node\Expr\BinaryOp $conditional)
    {
        if ($conditional->right instanceof PhpParser\Node\Expr\ConstFetch
            && strtolower($conditional->right->name->parts[0]) === 'true'
        ) {
            return self::ASSIGNMENT_TO_RIGHT;
        }

        if ($conditional->left instanceof PhpParser\Node\Expr\ConstFetch
            && strtolower($conditional->left->name->parts[0]) === 'true'
        ) {
            return self::ASSIGNMENT_TO_LEFT;
        }

        return null;
    }

    /**
     * @param   PhpParser\Node\Expr\BinaryOp    $conditional
     *
     * @return  int|null
     */
    protected static function hasEmptyArrayVariable(PhpParser\Node\Expr\BinaryOp $conditional)
    {
        if ($conditional->right instanceof PhpParser\Node\Expr\Array_
            && !$conditional->right->items
        ) {
            return self::ASSIGNMENT_TO_RIGHT;
        }

        if ($conditional->left instanceof PhpParser\Node\Expr\Array_
            && !$conditional->left->items
        ) {
            return self::ASSIGNMENT_TO_LEFT;
        }

        return null;
    }

    /**
     * @param   PhpParser\Node\Expr\BinaryOp    $conditional
     *
     * @return  false|int
     */
    protected static function hasGetTypeCheck(PhpParser\Node\Expr\BinaryOp $conditional)
    {
        if ($conditional->right instanceof PhpParser\Node\Expr\FuncCall
            && $conditional->right->name instanceof PhpParser\Node\Name
            && strtolower($conditional->right->name->parts[0]) === 'gettype'
            && $conditional->right->args
            && $conditional->left instanceof PhpParser\Node\Scalar\String_
        ) {
            return self::ASSIGNMENT_TO_RIGHT;
        }

        if ($conditional->left instanceof PhpParser\Node\Expr\FuncCall
            && $conditional->left->name instanceof PhpParser\Node\Name
            && strtolower($conditional->left->name->parts[0]) === 'gettype'
            && $conditional->left->args
            && $conditional->right instanceof PhpParser\Node\Scalar\String_
        ) {
            return self::ASSIGNMENT_TO_LEFT;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\BinaryOp    $conditional
     *
     * @return  false|int
     */
    protected static function hasGetClassCheck(
        PhpParser\Node\Expr\BinaryOp $conditional,
        FileSource $source
    ) {
        if (!$source instanceof StatementsAnalyzer) {
            return false;
        }

        $right_get_class = $conditional->right instanceof PhpParser\Node\Expr\FuncCall
            && $conditional->right->name instanceof PhpParser\Node\Name
            && strtolower($conditional->right->name->parts[0]) === 'get_class';

        $right_static_class = $conditional->right instanceof PhpParser\Node\Expr\ClassConstFetch
            && $conditional->right->class instanceof PhpParser\Node\Name
            && $conditional->right->class->parts === ['static']
            && $conditional->right->name instanceof PhpParser\Node\Identifier
            && strtolower($conditional->right->name->name) === 'class';

        $left_class_string = $conditional->left instanceof PhpParser\Node\Expr\ClassConstFetch
            && $conditional->left->class instanceof PhpParser\Node\Name
            && $conditional->left->name instanceof PhpParser\Node\Identifier
            && strtolower($conditional->left->name->name) === 'class';

        $left_type = $source->node_data->getType($conditional->left);

        $left_class_string_t = false;

        if ($left_type && $left_type->isSingle()) {
            foreach ($left_type->getAtomicTypes() as $type_part) {
                if ($type_part instanceof Type\Atomic\TClassString) {
                    $left_class_string_t = true;
                }
            }
        }

        if (($right_get_class || $right_static_class) && ($left_class_string || $left_class_string_t)) {
            return self::ASSIGNMENT_TO_RIGHT;
        }

        $left_get_class = $conditional->left instanceof PhpParser\Node\Expr\FuncCall
            && $conditional->left->name instanceof PhpParser\Node\Name
            && strtolower($conditional->left->name->parts[0]) === 'get_class';

        $left_static_class = $conditional->left instanceof PhpParser\Node\Expr\ClassConstFetch
            && $conditional->left->class instanceof PhpParser\Node\Name
            && $conditional->left->class->parts === ['static']
            && $conditional->left->name instanceof PhpParser\Node\Identifier
            && strtolower($conditional->left->name->name) === 'class';

        $right_class_string = $conditional->right instanceof PhpParser\Node\Expr\ClassConstFetch
            && $conditional->right->class instanceof PhpParser\Node\Name
            && $conditional->right->name instanceof PhpParser\Node\Identifier
            && strtolower($conditional->right->name->name) === 'class';

        $right_type = $source->node_data->getType($conditional->right);

        $right_class_string_t = false;

        if ($right_type && $right_type->isSingle()) {
            foreach ($right_type->getAtomicTypes() as $type_part) {
                if ($type_part instanceof Type\Atomic\TClassString) {
                    $right_class_string_t = true;
                }
            }
        }

        if (($left_get_class || $left_static_class) && ($right_class_string || $right_class_string_t)) {
            return self::ASSIGNMENT_TO_LEFT;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\BinaryOp    $conditional
     *
     * @return  false|int
     */
    protected static function hasNonEmptyCountEqualityCheck(
        PhpParser\Node\Expr\BinaryOp $conditional,
        ?int &$min_count
    ) {
        $left_count = $conditional->left instanceof PhpParser\Node\Expr\FuncCall
            && $conditional->left->name instanceof PhpParser\Node\Name
            && strtolower($conditional->left->name->parts[0]) === 'count'
            && $conditional->left->args;

        $operator_greater_than_or_equal =
            $conditional instanceof PhpParser\Node\Expr\BinaryOp\Identical
            || $conditional instanceof PhpParser\Node\Expr\BinaryOp\Equal
            || $conditional instanceof PhpParser\Node\Expr\BinaryOp\Greater
            || $conditional instanceof PhpParser\Node\Expr\BinaryOp\GreaterOrEqual;

        if ($left_count
            && $conditional->right instanceof PhpParser\Node\Scalar\LNumber
            && $operator_greater_than_or_equal
            && $conditional->right->value >= (
                $conditional instanceof PhpParser\Node\Expr\BinaryOp\Greater
                ? 0
                : 1
            )
        ) {
            $min_count = $conditional->right->value +
                ($conditional instanceof PhpParser\Node\Expr\BinaryOp\Greater ? 1 : 0);

            return self::ASSIGNMENT_TO_RIGHT;
        }

        $right_count = $conditional->right instanceof PhpParser\Node\Expr\FuncCall
            && $conditional->right->name instanceof PhpParser\Node\Name
            && strtolower($conditional->right->name->parts[0]) === 'count'
            && $conditional->right->args;

        $operator_less_than_or_equal =
            $conditional instanceof PhpParser\Node\Expr\BinaryOp\Identical
            || $conditional instanceof PhpParser\Node\Expr\BinaryOp\Equal
            || $conditional instanceof PhpParser\Node\Expr\BinaryOp\Smaller
            || $conditional instanceof PhpParser\Node\Expr\BinaryOp\SmallerOrEqual;

        if ($right_count
            && $conditional->left instanceof PhpParser\Node\Scalar\LNumber
            && $operator_less_than_or_equal
            && $conditional->left->value >= (
                $conditional instanceof PhpParser\Node\Expr\BinaryOp\Smaller ? 0 : 1
            )
        ) {
            $min_count = $conditional->left->value +
                ($conditional instanceof PhpParser\Node\Expr\BinaryOp\Smaller ? 1 : 0);

            return self::ASSIGNMENT_TO_LEFT;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\BinaryOp    $conditional
     *
     * @return  false|int
     */
    protected static function hasReconcilableNonEmptyCountEqualityCheck(PhpParser\Node\Expr\BinaryOp $conditional)
    {
        $left_count = $conditional->left instanceof PhpParser\Node\Expr\FuncCall
            && $conditional->left->name instanceof PhpParser\Node\Name
            && strtolower($conditional->left->name->parts[0]) === 'count';

        $right_number = $conditional->right instanceof PhpParser\Node\Scalar\LNumber
            && $conditional->right->value === (
                $conditional instanceof PhpParser\Node\Expr\BinaryOp\Greater ? 0 : 1);

        $operator_greater_than_or_equal =
            $conditional instanceof PhpParser\Node\Expr\BinaryOp\Identical
            || $conditional instanceof PhpParser\Node\Expr\BinaryOp\Equal
            || $conditional instanceof PhpParser\Node\Expr\BinaryOp\Greater
            || $conditional instanceof PhpParser\Node\Expr\BinaryOp\GreaterOrEqual;

        if ($left_count && $right_number && $operator_greater_than_or_equal) {
            return self::ASSIGNMENT_TO_RIGHT;
        }

        $right_count = $conditional->right instanceof PhpParser\Node\Expr\FuncCall
            && $conditional->right->name instanceof PhpParser\Node\Name
            && strtolower($conditional->right->name->parts[0]) === 'count';

        $left_number = $conditional->left instanceof PhpParser\Node\Scalar\LNumber
            && $conditional->left->value === (
                $conditional instanceof PhpParser\Node\Expr\BinaryOp\Smaller ? 0 : 1);

        $operator_less_than_or_equal =
            $conditional instanceof PhpParser\Node\Expr\BinaryOp\Identical
            || $conditional instanceof PhpParser\Node\Expr\BinaryOp\Equal
            || $conditional instanceof PhpParser\Node\Expr\BinaryOp\Smaller
            || $conditional instanceof PhpParser\Node\Expr\BinaryOp\SmallerOrEqual;

        if ($right_count && $left_number && $operator_less_than_or_equal) {
            return self::ASSIGNMENT_TO_LEFT;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\BinaryOp    $conditional
     *
     * @return  false|int
     */
    protected static function hasTypedValueComparison(
        PhpParser\Node\Expr\BinaryOp $conditional,
        FileSource $source
    ) {
        if (!$source instanceof StatementsAnalyzer) {
            return false;
        }

        if (($right_type = $source->node_data->getType($conditional->right))
            && ((!$conditional->right instanceof PhpParser\Node\Expr\Variable
                    && !$conditional->right instanceof PhpParser\Node\Expr\PropertyFetch
                    && !$conditional->right instanceof PhpParser\Node\Expr\StaticPropertyFetch)
                || $conditional->left instanceof PhpParser\Node\Expr\Variable
                || $conditional->left instanceof PhpParser\Node\Expr\PropertyFetch
                || $conditional->left instanceof PhpParser\Node\Expr\StaticPropertyFetch)
            && count($right_type->getAtomicTypes()) === 1
            && !$right_type->hasMixed()
        ) {
            return self::ASSIGNMENT_TO_RIGHT;
        }

        if (($left_type = $source->node_data->getType($conditional->left))
            && !$conditional->left instanceof PhpParser\Node\Expr\Variable
            && !$conditional->left instanceof PhpParser\Node\Expr\PropertyFetch
            && !$conditional->left instanceof PhpParser\Node\Expr\StaticPropertyFetch
            && count($left_type->getAtomicTypes()) === 1
            && !$left_type->hasMixed()
        ) {
            return self::ASSIGNMENT_TO_LEFT;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  bool
     */
    protected static function hasNullCheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name && strtolower($stmt->name->parts[0]) === 'is_null') {
            return true;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  bool
     */
    protected static function hasIsACheck(
        PhpParser\Node\Expr\FuncCall $stmt,
        StatementsAnalyzer $source
    ) {
        if ($stmt->name instanceof PhpParser\Node\Name
            && (strtolower($stmt->name->parts[0]) === 'is_a'
                || strtolower($stmt->name->parts[0]) === 'is_subclass_of')
            && isset($stmt->args[1])
        ) {
            $second_arg = $stmt->args[1]->value;

            if ($second_arg instanceof PhpParser\Node\Scalar\String_
                || (
                    $second_arg instanceof PhpParser\Node\Expr\ClassConstFetch
                    && $second_arg->class instanceof PhpParser\Node\Name
                    && $second_arg->name instanceof PhpParser\Node\Identifier
                    && strtolower($second_arg->name->name) === 'class'
                )
                || (($second_arg_type = $source->node_data->getType($second_arg))
                    && $second_arg_type->hasString())
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  bool
     */
    protected static function hasArrayCheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name && strtolower($stmt->name->parts[0]) === 'is_array') {
            return true;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  bool
     */
    protected static function hasStringCheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name && strtolower($stmt->name->parts[0]) === 'is_string') {
            return true;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  bool
     */
    protected static function hasBoolCheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name && strtolower($stmt->name->parts[0]) === 'is_bool') {
            return true;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  bool
     */
    protected static function hasObjectCheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name && $stmt->name->parts === ['is_object']) {
            return true;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  bool
     */
    protected static function hasNumericCheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name && $stmt->name->parts === ['is_numeric']) {
            return true;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  bool
     */
    protected static function hasIterableCheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name && strtolower($stmt->name->parts[0]) === 'is_iterable') {
            return true;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  bool
     */
    protected static function hasCountableCheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name && strtolower($stmt->name->parts[0]) === 'is_countable') {
            return true;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  0|1|2
     */
    protected static function hasClassExistsCheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name
            && strtolower($stmt->name->parts[0]) === 'class_exists'
        ) {
            if (!isset($stmt->args[1])) {
                return 2;
            }

            $second_arg = $stmt->args[1]->value;

            if ($second_arg instanceof PhpParser\Node\Expr\ConstFetch
                && strtolower($second_arg->name->parts[0]) === 'true'
            ) {
                return 2;
            }

            return 1;
        }

        return 0;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  0|1|2
     */
    protected static function hasTraitExistsCheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name
            && strtolower($stmt->name->parts[0]) === 'trait_exists'
        ) {
            if (!isset($stmt->args[1])) {
                return 2;
            }

            $second_arg = $stmt->args[1]->value;

            if ($second_arg instanceof PhpParser\Node\Expr\ConstFetch
                && strtolower($second_arg->name->parts[0]) === 'true'
            ) {
                return 2;
            }

            return 1;
        }

        return 0;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  bool
     */
    protected static function hasInterfaceExistsCheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name
            && strtolower($stmt->name->parts[0]) === 'interface_exists'
        ) {
            return true;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  bool
     */
    protected static function hasFunctionExistsCheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name && strtolower($stmt->name->parts[0]) === 'function_exists') {
            return true;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  bool
     */
    protected static function hasIntCheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name &&
            ($stmt->name->parts === ['is_int'] ||
                $stmt->name->parts === ['is_integer'] ||
                $stmt->name->parts === ['is_long'])
        ) {
            return true;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  bool
     */
    protected static function hasFloatCheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name &&
            ($stmt->name->parts === ['is_float'] ||
                $stmt->name->parts === ['is_real'] ||
                $stmt->name->parts === ['is_double'])
        ) {
            return true;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  bool
     */
    protected static function hasResourceCheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name && $stmt->name->parts === ['is_resource']) {
            return true;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  bool
     */
    protected static function hasScalarCheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name && $stmt->name->parts === ['is_scalar']) {
            return true;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  bool
     */
    protected static function hasCallableCheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name && $stmt->name->parts === ['is_callable']) {
            return true;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  bool
     */
    protected static function hasInArrayCheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name
            && $stmt->name->parts === ['in_array']
            && isset($stmt->args[2])
        ) {
            $second_arg = $stmt->args[2]->value;

            if ($second_arg instanceof PhpParser\Node\Expr\ConstFetch
                && strtolower($second_arg->name->parts[0]) === 'true'
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  bool
     */
    protected static function hasNonEmptyCountCheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name
            && $stmt->name->parts === ['count']
        ) {
            return true;
        }

        return false;
    }

    /**
     * @param   PhpParser\Node\Expr\FuncCall    $stmt
     *
     * @return  bool
     */
    protected static function hasArrayKeyExistsCheck(PhpParser\Node\Expr\FuncCall $stmt)
    {
        if ($stmt->name instanceof PhpParser\Node\Name && $stmt->name->parts === ['array_key_exists']) {
            return true;
        }

        return false;
    }
}