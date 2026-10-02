<?php

/**
 * ScopeAlgebra — parses SMART v1 / v2 scope strings into effective permissions so the
 * Scope Lab can compare what was asked for, what was granted and what the server enforces
 * on a like-for-like basis rather than by string equality.
 *
 * Why not compare strings: a server is free to answer `user/Patient.read` with
 * `user/Patient.rs` (same permissions, different spelling), and the bugs worth catching are
 * the ones where the *permissions* change -- a write silently dropped by the consent form, or
 * a read granted that was never requested. So everything is reduced to
 * context/Resource => set of c r u d s, and the string diff is reported only as information.
 *
 *   v1  read  => r s
 *       write => c u d
 *       *     => c r u d s
 *   v2  any ordered subset of c r u d s, e.g. rs, cu, cruds
 *       `?name=value` suffix = granular constraint (SMART v2 "finer-grained" scopes)
 *
 * @package   OpenEMR API
 * @link      http://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2026 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\ApiExplorer\ScopeLab;

final class ScopeAlgebra
{
    public const PERMS = ['c', 'r', 'u', 'd', 's'];

    private const RESOURCE_PATTERN =
        '~^(patient|user|system)/(\*|[A-Za-z][A-Za-z0-9_]*)\.([A-Za-z*]+|\$[A-Za-z-]+)(\?.*)?$~';

    /**
     * Splits a scope string on whitespace, dropping empties and duplicates, preserving order.
     *
     * @return list<string>
     */
    public static function split(string $scopes): array
    {
        $parts = preg_split('/\s+/', trim($scopes)) ?: [];
        return array_values(array_unique(array_filter($parts, static fn(string $s): bool => $s !== '')));
    }

    /**
     * Parses one scope. Non-resource scopes (openid, api:fhir, launch/patient, fhirUser...)
     * come back with kind 'other'. Resource scopes with an operation suffix ($export) come
     * back with kind 'operation'. An unrecognised or out-of-order v2 permission string comes
     * back with kind 'resource' and valid=false, so the caller can predict a rejection.
     *
     * @return array{
     *     scope: string, kind: string, context: ?string, resource: ?string, version: ?string,
     *     perms: list<string>, constraint: ?string, valid: bool, reason: ?string
     * }
     */
    public static function parse(string $scope): array
    {
        $out = [
            'scope' => $scope,
            'kind' => 'other',
            'context' => null,
            'resource' => null,
            'version' => null,
            'perms' => [],
            'constraint' => null,
            'valid' => true,
            'reason' => null,
        ];
        if (preg_match(self::RESOURCE_PATTERN, $scope, $m) !== 1) {
            return $out;
        }
        $out['context'] = $m[1];
        $out['resource'] = $m[2];
        $action = $m[3];
        $out['constraint'] = isset($m[4]) && $m[4] !== '' ? substr($m[4], 1) : null;

        if ($action[0] === '$') {
            $out['kind'] = 'operation';
            return $out;
        }
        $out['kind'] = 'resource';

        if ($action === 'read') {
            $out['version'] = 'v1';
            $out['perms'] = ['r', 's'];
        } elseif ($action === 'write') {
            $out['version'] = 'v1';
            $out['perms'] = ['c', 'u', 'd'];
        } elseif ($action === '*') {
            $out['version'] = 'v1';
            $out['perms'] = self::PERMS;
        } else {
            $out['version'] = 'v2';
            $letters = str_split($action);
            $lastIndex = -1;
            foreach ($letters as $letter) {
                $index = array_search($letter, self::PERMS, true);
                if ($index === false) {
                    $out['valid'] = false;
                    $out['reason'] = "'{$letter}' is not a SMART v2 permission";
                    break;
                }
                if ($index <= $lastIndex) {
                    $out['valid'] = false;
                    $out['reason'] = 'SMART v2 permissions must appear once each in c r u d s order';
                    break;
                }
                $lastIndex = $index;
            }
            $out['perms'] = $out['valid'] ? $letters : [];
        }
        if ($out['constraint'] !== null && $out['version'] === 'v1') {
            $out['valid'] = false;
            $out['reason'] = 'granular ?query constraints are SMART v2 only';
        }

        return $out;
    }

    /**
     * Version mix of a scope set: 'v1', 'v2', 'mixed' or 'none'.
     *
     * @param list<string> $scopes
     */
    public static function versionOf(array $scopes): string
    {
        $seen = [];
        foreach ($scopes as $scope) {
            $parsed = self::parse($scope);
            if ($parsed['kind'] === 'resource' && $parsed['version'] !== null) {
                $seen[$parsed['version']] = true;
            }
        }
        if (count($seen) > 1) {
            return 'mixed';
        }
        return $seen === [] ? 'none' : (string) array_key_first($seen);
    }

    /**
     * Reduces a scope set to effective permissions.
     *
     * Returns [
     *   'plain'     => ['user/Patient' => ['r' => true, 's' => true], ...],
     *   'granular'  => ['user/Observation' => ['category=...|laboratory' => ['r'=>true,'s'=>true]]],
     *   'other'     => ['openid', 'api:fhir', ...],
     *   'operations'=> ['system/*.$export', ...],
     *   'invalid'   => [['scope' => ..., 'reason' => ...]],
     * ]
     *
     * @param list<string> $scopes
     * @return array{
     *     plain: array<string, array<string, bool>>,
     *     granular: array<string, array<string, array<string, bool>>>,
     *     other: list<string>, operations: list<string>,
     *     invalid: list<array{scope: string, reason: ?string}>
     * }
     */
    public static function effective(array $scopes): array
    {
        $plain = [];
        $granular = [];
        $other = [];
        $operations = [];
        $invalid = [];
        foreach ($scopes as $scope) {
            $parsed = self::parse($scope);
            if ($parsed['kind'] === 'other') {
                $other[] = $scope;
                continue;
            }
            if ($parsed['kind'] === 'operation') {
                $operations[] = $scope;
                continue;
            }
            if (!$parsed['valid']) {
                $invalid[] = ['scope' => $scope, 'reason' => $parsed['reason']];
                continue;
            }
            $key = $parsed['context'] . '/' . $parsed['resource'];
            foreach ($parsed['perms'] as $perm) {
                if ($parsed['constraint'] === null) {
                    $plain[$key][$perm] = true;
                } else {
                    $granular[$key][$parsed['constraint']][$perm] = true;
                }
            }
        }
        ksort($plain);
        ksort($granular);

        return [
            'plain' => $plain,
            'granular' => $granular,
            'other' => array_values(array_unique($other)),
            'operations' => array_values(array_unique($operations)),
            'invalid' => $invalid,
        ];
    }

    /**
     * Permission-level diff between an expected and an actual scope set.
     *
     * lost   = permissions expected but missing (e.g. consent form dropped a write)
     * gained = permissions present but not expected (escalation -- always a finding)
     * The string diff is included for context; a string that moved while its permissions
     * did not is a respelling, not a bug.
     *
     * @param list<string> $expected
     * @param list<string> $actual
     * @return array{
     *     lost: array<string, list<string>>, gained: array<string, list<string>>,
     *     lostGranular: list<string>, gainedGranular: list<string>,
     *     lostOther: list<string>, gainedOther: list<string>,
     *     missingStrings: list<string>, extraStrings: list<string>, equivalent: bool
     * }
     */
    public static function diff(array $expected, array $actual): array
    {
        $e = self::effective($expected);
        $a = self::effective($actual);

        $lost = [];
        $gained = [];
        foreach (array_unique(array_merge(array_keys($e['plain']), array_keys($a['plain']))) as $key) {
            $ep = array_keys($e['plain'][$key] ?? []);
            $ap = array_keys($a['plain'][$key] ?? []);
            $l = array_values(array_diff($ep, $ap));
            $g = array_values(array_diff($ap, $ep));
            if ($l !== []) {
                $lost[$key] = self::ordered($l);
            }
            if ($g !== []) {
                $gained[$key] = self::ordered($g);
            }
        }
        ksort($lost);
        ksort($gained);

        $flatGranular = static function (array $granular): array {
            $flat = [];
            foreach ($granular as $key => $constraints) {
                foreach ($constraints as $constraint => $perms) {
                    $flat[] = $key . '.' . implode('', self::ordered(array_keys($perms))) . '?' . $constraint;
                }
            }
            sort($flat);
            return $flat;
        };
        $eg = $flatGranular($e['granular']);
        $ag = $flatGranular($a['granular']);

        $result = [
            'lost' => $lost,
            'gained' => $gained,
            'lostGranular' => array_values(array_diff($eg, $ag)),
            'gainedGranular' => array_values(array_diff($ag, $eg)),
            'lostOther' => array_values(array_diff($e['other'], $a['other'])),
            'gainedOther' => array_values(array_diff($a['other'], $e['other'])),
            'missingStrings' => array_values(array_diff($expected, $actual)),
            'extraStrings' => array_values(array_diff($actual, $expected)),
            'equivalent' => false,
        ];
        $result['equivalent'] = $lost === [] && $gained === []
            && $result['lostGranular'] === [] && $result['gainedGranular'] === []
            && $result['lostOther'] === [] && $result['gainedOther'] === [];

        return $result;
    }

    /**
     * Predicts whether a request needing $perm on $resource is authorised by a scope set.
     *
     * Returns 'allow', 'deny', or 'either' (only a granular scope covers the resource and the
     * probe carries no matching constraint -- the server may legitimately filter or refuse).
     *
     * @param list<string> $scopes
     * @param string $gate     'api:fhir' or 'api:oemr' -- the API family gate scope
     * @param string|null $query  the probe's own query ("category=...|laboratory"), for granular matching
     */
    public static function predict(array $scopes, string $gate, string $resource, string $perm, ?string $query = null): string
    {
        $eff = self::effective($scopes);
        if (!in_array($gate, $eff['other'], true)) {
            return 'deny';
        }
        foreach (['patient', 'user', 'system'] as $context) {
            foreach ([$resource, '*'] as $name) {
                if (!empty($eff['plain']["{$context}/{$name}"][$perm])) {
                    return 'allow';
                }
            }
        }
        $sawGranular = false;
        foreach (['patient', 'user', 'system'] as $context) {
            foreach ($eff['granular']["{$context}/{$resource}"] ?? [] as $constraint => $perms) {
                if (empty($perms[$perm])) {
                    continue;
                }
                $sawGranular = true;
                if ($query !== null && self::constraintMatches((string) $constraint, $query)) {
                    return 'allow';
                }
            }
        }
        if ($sawGranular) {
            return $query === null ? 'either' : 'deny';
        }

        return 'deny';
    }

    /**
     * The constraints that limit $perm on $resource, or null when nothing limits it: a plain
     * scope grants the permission unrestricted, or no scope grants it at all.
     *
     * @param list<string> $scopes
     * @return list<string>|null
     */
    public static function constraintsFor(array $scopes, string $resource, string $perm): ?array
    {
        $eff = self::effective($scopes);
        $constraints = [];
        foreach (['patient', 'user', 'system'] as $context) {
            foreach ([$resource, '*'] as $name) {
                if (!empty($eff['plain']["{$context}/{$name}"][$perm])) {
                    return null;
                }
            }
            foreach ($eff['granular']["{$context}/{$resource}"] ?? [] as $constraint => $perms) {
                if (!empty($perms[$perm])) {
                    $constraints[] = (string) $constraint;
                }
            }
        }

        return $constraints === [] ? null : array_values(array_unique($constraints));
    }

    /**
     * True when a returned FHIR resource (decoded JSON) satisfies every name=value pair of a
     * scope constraint. The value is `system|code` or a bare `code` and is matched against the
     * codings of the element named by the pair (a CodeableConcept, a list of them, a Coding or
     * a code). An element that is absent or not coded does not satisfy the constraint, which
     * is how the server treats it.
     *
     * @param array<array-key, mixed> $resource
     */
    public static function resourceSatisfies(array $resource, string $constraint): bool
    {
        $pairs = self::pairs($constraint);
        if ($pairs === []) {
            return false;
        }
        foreach ($pairs as $name => $value) {
            $parts = explode('|', $value, 2);
            $system = count($parts) === 2 ? $parts[0] : null;
            $code = count($parts) === 2 ? $parts[1] : $parts[0];
            $matched = false;
            foreach (self::codings($resource[$name] ?? null) as $coding) {
                if ($coding['code'] === $code && ($system === null || $coding['system'] === $system)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return list<array{system: ?string, code: string}>
     */
    private static function codings(mixed $element): array
    {
        if (is_string($element)) {
            return [['system' => null, 'code' => $element]];
        }
        if (!is_array($element)) {
            return [];
        }
        if (isset($element['code']) && is_string($element['code'])) {
            return [['system' => is_string($element['system'] ?? null) ? $element['system'] : null, 'code' => $element['code']]];
        }
        $out = [];
        foreach (isset($element['coding']) && is_array($element['coding']) ? $element['coding'] : $element as $child) {
            if (is_array($child) || is_string($child)) {
                array_push($out, ...self::codings($child));
            }
        }
        return $out;
    }

    /**
     * True when every name=value pair in the scope constraint appears in the probe query.
     */
    public static function constraintMatches(string $constraint, string $query): bool
    {
        $want = self::pairs($constraint);
        $have = self::pairs($query);
        foreach ($want as $name => $value) {
            if (!isset($have[$name]) || $have[$name] !== $value) {
                return false;
            }
        }
        return $want !== [];
    }

    /**
     * Every resource named by a scope set, bucketed by case: FHIR (Capitalised) vs the
     * standard api:oemr names (lowercase). '*' is dropped -- it is not probeable by itself.
     *
     * @param list<string> $scopes
     * @return array{fhir: list<string>, standard: list<string>}
     */
    public static function resources(array $scopes): array
    {
        $fhir = [];
        $standard = [];
        foreach ($scopes as $scope) {
            $parsed = self::parse($scope);
            if ($parsed['kind'] !== 'resource' || $parsed['resource'] === null || $parsed['resource'] === '*') {
                continue;
            }
            if (ctype_upper($parsed['resource'][0])) {
                $fhir[$parsed['resource']] = true;
            } else {
                $standard[$parsed['resource']] = true;
            }
        }
        return ['fhir' => array_keys($fhir), 'standard' => array_keys($standard)];
    }

    /**
     * @param list<string> $perms
     * @return list<string>
     */
    public static function ordered(array $perms): array
    {
        return array_values(array_filter(self::PERMS, static fn(string $p): bool => in_array($p, $perms, true)));
    }

    /**
     * @return array<string, string>
     */
    private static function pairs(string $query): array
    {
        $pairs = [];
        foreach (explode('&', ltrim($query, '?')) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $pairs[rawurldecode($name)] = rawurldecode($value);
        }
        return $pairs;
    }
}
