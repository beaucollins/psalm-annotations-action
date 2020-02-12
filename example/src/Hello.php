<?php
namespace Collins\Example;

final class Hello {
 
    /**
     * @param Action<string,int> $action
     * @return string
     */
    public static function main( $action ) {
        return $action->perform( 'hello', 'there' );
    }

}