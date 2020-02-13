<?php
namespace Collins\Example;

final class Hello {
 
    /**
     * @param Action<string,int> $action
     * @return string
     */
    public static function main( $action ) {
        $map = new ActionMap(
            $action,
            /**
             * @var Action<int,string>
             */
            new ClosureAction(
                /**
                 * @param int $incoming
                 */
                function ( $incoming ) {
                    return (string) $incoming;
                }
            )
        );
        return $action->perform( 'hello', 'there', 'four' );
    }

}
