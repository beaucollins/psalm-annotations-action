<?php
namespace Collins\Example;

final class Hello {
 
    /**
     * @param Action<string,int> $action
     * @return array
     */
    public static function main( $action ) {
        $map = new ActionMap(
            $action,
            /**
             * @var Action<float,string>
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
        return $map->perform( 'hello' );
    }

}
