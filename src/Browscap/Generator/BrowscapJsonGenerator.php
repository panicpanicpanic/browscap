<?php

namespace Browscap\Generator;

/**
 * Class BrowscapJsonGenerator
 *
 * @package Browscap\Generator
 */
class BrowscapJsonGenerator extends AbstractGenerator
{
    /**
     * Options for regex patterns.
     *
     * REGEX_DELIMITER: Delimiter of all the regex patterns in the whole class.
     * REGEX_MODIFIERS: Regex modifiers.
     */
    const REGEX_DELIMITER = '@';
    const REGEX_MODIFIERS = 'i';
    const COMPRESSION_PATTERN_START = '@';
    const COMPRESSION_PATTERN_DELIMITER = '|';

    /**
     * Generate and return the formatted browscap data
     *
     * @return string
     */
    public function generate()
    {
        $this->logger->debug('build output for processed json file');

        if (!empty($this->collectionData['DefaultProperties'])) {
            $defaultPropertyData = $this->collectionData['DefaultProperties'];
        } else {
            $defaultPropertyData = array();
        }

        return $this->render(
            $this->collectionData,
            array_keys(array('Parent' => '') + $defaultPropertyData)
        );
    }

    /**
     * Generate the header
     *
     * @return array
     */
    private function renderHeader()
    {
        $this->logger->debug('rendering comments');
        $header = array();

        foreach ($this->getComments() as $comment) {
            $header[] = $comment;
        }

        return $header;
    }

    /**
     * renders the version information
     *
     * @return array
     */
    private function renderVersion()
    {
        $this->logger->debug('rendering version information');

        $versionData = $this->getVersionData();

        if (!isset($versionData['version'])) {
            $versionData['version'] = '0';
        }

        if (!isset($versionData['released'])) {
            $versionData['released'] = '';
        }

        return array(
            'Version'  => $versionData['version'],
            'Released' => $versionData['released'],
        );
    }

    /**
     * renders all found useragents into a string
     *
     * @param array[] $allInputDivisions
     * @param array   $allProperties
     *
     * @throws \InvalidArgumentException
     * @return string
     */
    private function render(array $allInputDivisions, array $allProperties)
    {
        $this->logger->debug('rendering all divisions');

        $allDivisions = array();

        foreach ($allInputDivisions as $key => $properties) {
            if (!isset($properties['division'])) {
                throw new \InvalidArgumentException('"division" is missing for key "' . $key . '"');
            }

            $this->logger->debug(
                'checking division "' . $properties['division'] . '" - "' . $key . '"'
            );

            if (!$this->firstCheckProperty($key, $properties, $allInputDivisions)) {
                $this->logger->debug('first check failed on key "' . $key . '" -> skipped');

                continue;
            }

            if (!in_array($key, array('DefaultProperties', '*'))) {
                $parent = $allInputDivisions[$properties['Parent']];
            } else {
                $parent = array();
            }

            $propertiesToOutput = $properties;

            foreach ($propertiesToOutput as $property => $value) {
                if (!isset($parent[$property])) {
                    continue;
                }

                $parentProperty = $parent[$property];

                switch ((string) $parentProperty) {
                    case 'true':
                        $parentProperty = true;
                        break;
                    case 'false':
                        $parentProperty = false;
                        break;
                    default:
                        $parentProperty = trim($parentProperty);
                        break;
                }

                if ($parentProperty != $value) {
                    continue;
                }

                unset($propertiesToOutput[$property]);
            }

            $allDivisions[$key] = array();

            foreach ($allProperties as $property) {
                if (!isset($propertiesToOutput[$property])) {
                    continue;
                }

                if (!CollectionParser::isOutputProperty($property)) {
                    continue;
                }

                if (CollectionParser::isExtraProperty($property)) {
                    continue;
                }

                $value       = $propertiesToOutput[$property];
                $valueOutput = $value;

                switch (CollectionParser::getPropertyType($property)) {
                    case CollectionParser::TYPE_BOOLEAN:
                        if (true === $value || $value === 'true') {
                            $valueOutput = true;
                        } elseif (false === $value || $value === 'false') {
                            $valueOutput = false;
                        }
                        break;
                    case CollectionParser::TYPE_IN_ARRAY:
                        $valueOutput = CollectionParser::checkValueInArray($property, $value);
                        break;
                    default:
                        // nothing t do here
                        break;
                }

                $allDivisions[$key][$property] = $valueOutput;

                unset($value, $valueOutput);
            }
        }

        $output = array(
            'comments'             => $this->renderHeader(),
            'GJK_Browscap_Version' => $this->renderVersion(),
            'patterns'             => array(),
            'browsers'             => array(),
            'userAgents'           => array(),
        );

        array_unshift(
            $allProperties,
            'browser_name',
            'browser_name_regex',
            'browser_name_pattern'
        );
        ksort($allProperties);

        $tmp_user_agents = array_keys($allDivisions);

        $this->logger->debug('sort useragent rules by length');

        $fullLength    = array();
        $reducedLength = array();

        foreach ($tmp_user_agents as $k => $a) {
            $fullLength[$k]    = strlen($a);
            $reducedLength[$k] = strlen(str_replace(array('*', '?'), '', $a));
        }

        array_multisort(
            $fullLength, SORT_DESC, SORT_NUMERIC,
            $reducedLength, SORT_DESC, SORT_NUMERIC,
            $tmp_user_agents
        );

        unset($fullLength, $reducedLength);

        $user_agents_keys = array_flip($tmp_user_agents);
        $properties_keys  = array_flip($allProperties);

        $tmp_patterns = array();

        $this->logger->debug('process all useragents');

        foreach ($tmp_user_agents as $i => $user_agent) {
            if (empty($allDivisions[$user_agent]['Comment'])
                || false !== strpos($user_agent, '*')
                || false !== strpos($user_agent, '?')
            ) {
                $pattern = $this->pregQuote($user_agent);

                $matches_count = preg_match_all(self::REGEX_DELIMITER . '\d' . self::REGEX_DELIMITER, $pattern, $matches);

                if (!$matches_count) {
                    $tmp_patterns[$pattern] = $i;
                } else {
                    $compressed_pattern = preg_replace(self::REGEX_DELIMITER . '\d' . self::REGEX_DELIMITER, '(\d)', $pattern);

                    if (!isset($tmp_patterns[$compressed_pattern])) {
                        $tmp_patterns[$compressed_pattern] = array('first' => $pattern);
                    }

                    $tmp_patterns[$compressed_pattern][$i] = $matches[0];
                }
            }

            if (!empty($allDivisions[$user_agent]['Parent'])) {
                $parent = $allDivisions[$user_agent]['Parent'];

                $parent_key = $user_agents_keys[$parent];

                $allDivisions[$user_agent]['Parent']       = $parent_key;
                $output['userAgents'][$parent_key] = $tmp_user_agents[$parent_key];
            };

            $browser = array();
            foreach ($allDivisions[$user_agent] as $property => $value) {
                if (!isset($properties_keys[$property]) || !CollectionParser::isOutputProperty($property)) {
                    continue;
                }

                $browser[$property] = $value;
            }

            $output['browsers'][$i] = json_encode($browser, JSON_FORCE_OBJECT);
        }

        // reducing memory usage by unsetting $tmp_user_agents
        unset($tmp_user_agents);

        ksort($output['userAgents']);
        ksort($output['browsers']);

        $this->logger->debug('process all patterns');

        foreach ($tmp_patterns as $pattern => $pattern_data) {
            if (is_int($pattern_data) || is_string($pattern_data)) {
                $output['patterns'][$pattern] = $pattern_data;
            } elseif (2 == count($pattern_data)) {
                end($pattern_data);
                $output['patterns'][$pattern_data['first']] = key($pattern_data);
            } else {
                unset($pattern_data['first']);

                $pattern_data = $this->deduplicateCompressionPattern($pattern_data, $pattern);

                $output['patterns'][$pattern] = $pattern_data;
            }
        }

        // reducing memory usage by unsetting $tmp_user_agents
        unset($tmp_patterns);

        return json_encode($output, JSON_PRETTY_PRINT | JSON_FORCE_OBJECT);
    }

    /**
     * Converts browscap match patterns into preg match patterns.
     *
     * @param string $user_agent
     *
     * @return string
     */
    private function pregQuote($user_agent)
    {
        $pattern = preg_quote($user_agent, self::REGEX_DELIMITER);

        // the \\x replacement is a fix for "Der gro\xdfe BilderSauger 2.00u" user agent match
        return self::REGEX_DELIMITER
            . '^'
            . str_replace(array('\*', '\?', '\\x'), array('.*', '.', '\\\\x'), $pattern)
            . '$'
            . self::REGEX_DELIMITER;
    }

    /**
     * That looks complicated...
     *
     * All numbers are taken out into $matches, so we check if any of those numbers are identical
     * in all the $matches and if they are we restore them to the $pattern, removing from the $matches.
     * This gives us patterns with "(\d)" only in places that differ for some matches.
     *
     * @param array  $matches
     * @param string $pattern
     *
     * @return array of $matches
     */
    private function deduplicateCompressionPattern($matches, &$pattern)
    {
        $tmpMatches  = $matches;
        $first_match = array_shift($tmpMatches);
        $differences = array();

        foreach ($tmpMatches as $someMatch) {
            $differences += array_diff_assoc($first_match, $someMatch);
        }

        $identical = array_diff_key($first_match, $differences);

        $preparedMatches = array();

        foreach ($matches as $i => $someMatch) {
            $key = self::COMPRESSION_PATTERN_START
                . implode(self::COMPRESSION_PATTERN_DELIMITER, array_diff_assoc($someMatch, $identical));

            $preparedMatches[$key] = $i;
        }

        $patternParts = explode('(\d)', $pattern);

        foreach ($identical as $position => $value) {
            $patternParts[$position + 1] = $patternParts[$position] . $value . $patternParts[$position + 1];
            unset($patternParts[$position]);
        }

        $pattern = implode('(\d)', $patternParts);

        return $preparedMatches;
    }
}
