<?php

/**
 * Mockery
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://github.com/padraic/mockery/blob/master/LICENSE
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to padraic@php.net so we can send you a copy immediately.
 *
 * @category   Mockery
 * @package    Mockery
 * @copyright  Copyright (c) 2017 Dave Marshall https://github.com/davedevelopment
 * @license    http://github.com/padraic/mockery/blob/master/LICENSE New BSD License
 */

namespace Mockery;

/**
 * @internal
 */
class Reflector
{
    /**
     * Determine if the parameter is typed as an array.
     *
     * @param \ReflectionParameter $param
     *
     * @return bool
     */
    public static function isArray(\ReflectionParameter $param)
    {
        if (\PHP_VERSION_ID < 70100) {
            return $param->isArray();
        }

        $type = $param->getType();

        return $type instanceof \ReflectionNamedType ? $type->getName() === 'array' : false;
    }

    /**
     * Compute the string representation for the paramater type.
     *
     * @param \ReflectionParameter $param
     * @param bool $withoutNullable
     *
     * @return string|null
     */
    public static function getTypeHint(\ReflectionParameter $param, $withoutNullable = false)
    {
        // returns false if we are running PHP 7+
        $typeHint = self::getLegacyTypeHint($param);

        if ($typeHint !== false) {
            return $typeHint;
        }

        if (!$param->hasType()) {
            return null;
        }

        $type = $param->getType();
        $declaringClass = $param->getDeclaringClass()->getName();
        $typeHint = self::typeToString($type, $declaringClass);

        // PHP 7.1+ supports nullable types via a leading question mark
        return (!$withoutNullable && \PHP_VERSION_ID >= 70100 && $type->allowsNull()) ? sprintf('?%s', $typeHint) : $typeHint;
    }

    /**
     * Compute the string representation for the return type.
     *
     * @param \ReflectionParameter $param
     * @param bool $withoutNullable
     *
     * @return string|null
     */
    public static function getReturnType(\ReflectionMethod $method, $withoutNullable = false)
    {
        // Strip all return types for HHVM and skip PHP 5.
        if (method_exists($method, 'getReturnTypeText') || \PHP_VERSION_ID < 70000 || !$method->hasReturnType()) {
            return null;
        }

        $type = $method->getReturnType();
        $declaringClass = $method->getDeclaringClass()->getName();
        $typeHint = self::typeToString($type, $declaringClass);

        // PHP 7.1+ supports nullable types via a leading question mark
        return (!$withoutNullable && \PHP_VERSION_ID >= 70100 && $type->allowsNull()) ? sprintf('?%s', $typeHint) : $typeHint;
    }

    /**
     * Compute the legacy type hint.
     *
     * We return:
     *   - string: the legacy type hint
     *   - null: if there is no legacy type hint
     *   - false: if we must check for PHP 7+ typing
     *
     * @param \ReflectionParameter $param
     *
     * @return string|null|false
     */
    private static function getLegacyTypeHint(\ReflectionParameter $param)
    {
        // Handle arrays first
        if (\PHP_VERSION_ID < 71000 && $param->isArray()) {
            return 'array';
        }

        // Handle HHVM typing
        if (\method_exists($param, 'getTypehintText')) {
            $typeHint = $param->getTypehintText();

            // throw away HHVM scalar types
            if (\in_array($typeHint, array('int', 'integer', 'float', 'string', 'bool', 'boolean'), true)) {
                return null;
            }

            return sprintf('\\%s', $typeHint);
        }

        // Handle PHP 5 typing. Note that PHP < 5.4.1 has some incorrect
        // behaviour with a typehint of self and subclass signatures, so we
        // will process the type manually with regexp, falling back if needed!
        if (\PHP_VERSION_ID < 70000) {
            if (
                \PHP_VERSION_ID < 50401 && 
                \preg_match('/^Parameter #[0-9]+ \[ \<(required|optional)\> (?<typehint>\S+ )?.*\$' . $param->getName() . ' .*\]$/', (string) $param, $typeHintMatch) && 
                !empty($typeHintMatch['typehint'])
            ) {
                return $typeHintMatch['typehint'];
            }

            return sprintf('\\%s', $param->getClass());
        }

        return false;
    }

    /**
     * Get the string representation of the given type.
     *
     * This method MUST only be called on PHP 7+.
     *
     * @param \ReflectionType $type
     * @param string $declaringClass
     *
     * @return string|null
     */
    private static function typeToString(\ReflectionType $type, $declaringClass)
    {
        // PHP 8 union types can be recursively processed
        if ($type instanceof \ReflectionUnionType) {
            return \implode('|', \array_map(function (\ReflectionType $type) use ($declaringClass) {
                return self::typeToString($type, $declaringClass);
            }, $type->getTypes()));
        }

        // PHP 7.0 doesn't have named types, but 7.1+ does
        $typeHint = $type instanceof \ReflectionNamedType ? $type->getName() : (string) $type;

        // 'self' needs to be resolved to the name of the declaring class and
        // 'static' is a special type reserved as a return type in PHP 8
        return ($type->isBuiltin() || $typeHint === 'static') ? $typeHint : sprintf('\\%s', $typeHint === 'self' ? $declaringClass : $typeHint);
    }
}
