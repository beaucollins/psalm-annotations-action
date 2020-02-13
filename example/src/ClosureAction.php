<?php
namespace Collins\Example;

/**
 * @template I
 * @template O
 * 
 * @implements Action<I,O>
 */
final class ClosureAction implements Action {

    private $closure;

    /**
     * @param closure(I):O $closure
     */
    function __construct( $closure ) {
        $this->closure = $closure;
    }

    /**
     * @param I $input
     * @param O $output
     */
    public function perform( $input ) {
        $closure = $this->closure;

        return $closure( $input );
    }

}