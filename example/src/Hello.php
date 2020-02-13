<?php
namespace Collins\Example;

final class Hello {
 
    /**
     * @param Action<string,int> $action
     * @return int
     */
    public static function main( $action ) {
        $map = new ActionMap(
            $action,
            new ClosureAction(
                /**
                 * @param int $incoming
                 * @return \stdClass
                 */
                function ( $incoming ) {
                    $item = new \stdClass;
                    $item->value = $incoming;
                    return $item;
                }
            )
        );
        return $map->perform( 'hello' );
    }

}
