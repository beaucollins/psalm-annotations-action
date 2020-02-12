<?php
namespace Collins\Example;

/**
 * @template Input
 * @template Output
 */
interface Action {

    /**
     * @param Input $input;
     * @return Output
     */
    function perform( $input );

}