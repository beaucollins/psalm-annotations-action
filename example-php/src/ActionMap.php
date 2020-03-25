<?php
namespace Collins\Example;

/**
 * @template A
 * @template B
 * @template C
 * 
 * @implements Action<A,C>
 */
final class ActionMap implements Action {

    /**
     * @var Action<A,B>
     */
    private $action_1;

    /**
     * @var Action<B,C>
     */
    private $action_2;

    /**
     * @param Action<A,B> $action_1
     * @param Action<B,C> $action_2
     * @return Action<A,C>
     */
    function __construct( $action_1, $action_2 ) {
        $this->action_1 = $action_1;
        $this->action_2 = $action_2;
    }

    /**
     * @param A $input
     * @return C
     */
    public function perform( $input ) {
        return $this->action_2->perform( $this->action_1->perform( $input ) );
    }

}