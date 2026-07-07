<?php

class ConfigEditor
{
    private string $file;
    private string $data;

    /**
     * Initialize the config editor and load the file
     *
     * @param String $file
     */
    public function __construct(string $file)
    {
        if (!file_exists($file)) {
            throw new InvalidArgumentException("Config file not found: $file");
        }

        $this->file = $file;
        $this->data = file_get_contents($file);
    }

    /**
     * Change a config value
     *
     * @param mixed $key
     * @param mixed $value
     */
    public function set(mixed $key, mixed $value): void
    {
        $value = $this->formatValue($value);

        $path = $this->parseConfigPath((string)$key);

        if (count($path) > 1) {
            $this->updatePath($path, $value);
        } else {
            $this->updateFlatKey((string)$path[0], $value);
        }
    }

    /**
     * Save the edited config file
     */
    public function save(): void
    {
        file_put_contents($this->file, $this->data);
    }

    /**
     * Get the edited config content
     *
     * @return string
     */
    public function get(): string
    {
        return $this->data;
    }

    /**
     * Convert different types of values to strings compatible with PHP config syntax
     *
     * @param mixed $value
     * @return string
     */
    private function formatValue(mixed $value): string
    {
        if (is_string($value)) {
            $lower = strtolower($value);
            if ($lower === 'true') {
                $value = true;
            } elseif ($lower === 'false') {
                $value = false;
            }
        }

        if (empty($value) && !is_numeric($value) && !is_bool($value)) {
            return 'false';
        }

        return match (true) {
            is_array($value) => $this->formatArray($value),
            is_bool($value) => $value ? 'true' : 'false',
            is_float($value) => rtrim(rtrim(number_format($value, 8, '.', ''), '0'), '.'),
            is_int($value) => (string)$value,
            is_numeric($value) => (string)$value,
            is_string($value) => "'" . addcslashes($value, '\\\'') . "'",
            default => 'NULL',
        };
    }

    /**
     * Format array as PHP-style [...] string
     *
     * @param array $array
     * @return string
     */
    private function formatArray(array $array): string
    {
        $formatted = [];

        foreach ($array as $key => $val) {
            $keyStr = is_int($key) ? '' : $this->formatValue($key) . ' => ';
            $formatted[] = $keyStr . $this->formatValue($val);
        }

        return '[' . implode(', ', $formatted) . ']';
    }

    /**
     * Update or insert a top-level config key
     *
     * @param string $key
     * @param string $value
     * @return void
     */
    private function updateFlatKey(string $key, string $value): void
    {
        $range = $this->findConfigValueRange($key);

        if ($range) {
            $this->data = substr_replace($this->data, $value, $range['valueStart'], $range['valueEnd'] - $range['valueStart']);
        } else {
            $this->data .= PHP_EOL . "\$config['$key'] = $value;";
        }
    }

    /**
     * Update or insert a nested key inside an array config (e.g. $config['array'] = [...]).
     *
     * @param string $mainKey
     * @param string $subKey
     * @param string $newValue
     * @return void
     */
    private function updateNestedKey(string $mainKey, string $subKey, string $newValue): void
    {
        $range = $this->findConfigValueRange($mainKey);

        if (!$range) {
            $this->data .= PHP_EOL . "\$config['$mainKey'] = ['$subKey' => $newValue];";
            return;
        }

        $arraySource = substr($this->data, $range['valueStart'], $range['valueEnd'] - $range['valueStart']);
        $nestedRange = $this->findArrayValueRange($arraySource, $subKey);

        if ($nestedRange) {
            $this->data = substr_replace(
                $this->data,
                $newValue,
                $range['valueStart'] + $nestedRange['valueStart'],
                $nestedRange['valueEnd'] - $nestedRange['valueStart']
            );
            return;
        }

        $insertAt = $this->findArrayInsertOffset($arraySource);
        if ($insertAt === null) {
            $this->data = substr_replace($this->data, "['$subKey' => $newValue]", $range['valueStart'], $range['valueEnd'] - $range['valueStart']);
            return;
        }

        $innerStart = self::getArrayInnerStartOffset($arraySource);
        $body = trim(substr($arraySource, $innerStart, $insertAt - $innerStart));
        $prefix = $body === '' ? '' : ', ';
        $this->data = substr_replace($this->data, $prefix . "'$subKey' => $newValue", $range['valueStart'] + $insertAt, 0);
    }

    /**
     * Parse a config key path. Supports new dot/bracket paths and legacy main-sub keys.
     *
     * @param string $key
     * @return array
     */
    private function parseConfigPath(string $key): array
    {
        if (str_contains($key, '.') || str_contains($key, '[')) {
            preg_match_all('/([^\.\[\]]+)|\[([^\]]+)\]/', $key, $matches, PREG_SET_ORDER);
            $path = [];

            foreach ($matches as $match) {
                $segment = $match[1] !== '' ? $match[1] : $match[2];
                $path[] = ctype_digit($segment) ? (int)$segment : $segment;
            }

            return $path ?: [$key];
        }

        if (str_contains($key, '-')) {
            return explode('-', $key, 2);
        }

        return [$key];
    }

    /**
     * Update a config value using an explicit path like lottery_entries.0.cost.gold.
     *
     * @param array $path
     * @param string $value
     * @return void
     */
    private function updatePath(array $path, string $value): void
    {
        $mainKey = array_shift($path);
        $range = $this->findConfigValueRange((string)$mainKey);

        if (!$range) {
            $this->data .= PHP_EOL . "\$config['$mainKey'] = " . $this->buildArrayForPath($path, $value) . ';';
            return;
        }

        $arraySource = substr($this->data, $range['valueStart'], $range['valueEnd'] - $range['valueStart']);
        $pathRange = $this->findArrayPathValueRange($arraySource, $path);

        if ($pathRange) {
            $this->data = substr_replace(
                $this->data,
                $value,
                $range['valueStart'] + $pathRange['valueStart'],
                $pathRange['valueEnd'] - $pathRange['valueStart']
            );
            return;
        }

        $this->data = substr_replace(
            $this->data,
            $this->insertArrayPathValue($arraySource, $path, $value),
            $range['valueStart'],
            $range['valueEnd'] - $range['valueStart']
        );
    }

    /**
     * Build a nested array expression for a missing config path.
     *
     * @param array $path
     * @param string $value
     * @return string
     */
    private function buildArrayForPath(array $path, string $value): string
    {
        $segment = array_shift($path);
        $key = is_int($segment) ? $segment : var_export($segment, true);

        if (!$path) {
            return '[' . $key . ' => ' . $value . ']';
        }

        return '[' . $key . ' => ' . $this->buildArrayForPath($path, $value) . ']';
    }

    /**
     * Find a nested value by explicit path inside an array expression.
     *
     * @param string $arraySource
     * @param array $path
     * @return array|null
     */
    private function findArrayPathValueRange(string $arraySource, array $path): ?array
    {
        $currentSource = $arraySource;
        $baseOffset = 0;

        foreach ($path as $segment) {
            $range = $this->findArraySegmentValueRange($currentSource, $segment);
            if (!$range) {
                return null;
            }

            $baseOffset += $range['valueStart'];
            $currentSource = substr($currentSource, $range['valueStart'], $range['valueEnd'] - $range['valueStart']);
        }

        return [
            'valueStart' => $baseOffset,
            'valueEnd' => $baseOffset + strlen($currentSource),
        ];
    }

    /**
     * Find a direct child segment value in an array expression.
     *
     * @param string $arraySource
     * @param string|int $segment
     * @return array|null
     */
    private function findArraySegmentValueRange(string $arraySource, string|int $segment): ?array
    {
        $tokens = self::tokenizeWithOffsets('<?php ' . $arraySource);
        $baseOffset = strlen('<?php ');
        $depth = 0;
        $index = 0;
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $text = $tokens[$i]['text'];

            if ($depth === 1 && !self::isIgnorableToken($tokens[$i]) && $text !== ',' && $text !== ']' && $text !== ')') {
                $valueStartIndex = $i;
                $arrow = self::nextMeaningfulTokenIndex($tokens, $i + 1);
                $keyMatches = false;

                if ($arrow !== null && self::isToken($tokens[$arrow], T_DOUBLE_ARROW)) {
                    $keyMatches = self::arrayKeyMatches($tokens[$i], $segment);
                    $valueStartIndex = self::nextMeaningfulTokenIndex($tokens, $arrow + 1);
                } elseif (is_int($segment)) {
                    $keyMatches = $index === $segment;
                    $index++;
                }

                $valueEnd = self::findArrayItemEndOffset($tokens, $valueStartIndex ?? $i, 1);
                if ($valueEnd === null) {
                    return null;
                }

                if ($keyMatches && $valueStartIndex !== null) {
                    return [
                        'valueStart' => $tokens[$valueStartIndex]['offset'] - $baseOffset,
                        'valueEnd' => $valueEnd - $baseOffset,
                    ];
                }

                while ($i < $count && $tokens[$i]['offset'] < $valueEnd) {
                    $i++;
                }
                $i--;
                continue;
            }

            if ($text === '[' || $text === '(') {
                $depth++;
                continue;
            }

            if ($text === ']' || $text === ')') {
                $depth--;
                continue;
            }
        }

        return null;
    }


    /**
     * Insert a missing explicit path while preserving existing array content as much as possible.
     *
     * @param string $arraySource
     * @param array $path
     * @param string $value
     * @return string
     */
    private function insertArrayPathValue(string $arraySource, array $path, string $value): string
    {
        $insertAt = $this->findArrayInsertOffset($arraySource);
        if ($insertAt === null) {
            return $this->buildArrayForPath($path, $value);
        }

        $segment = array_shift($path);
        $itemValue = $path ? $this->buildArrayForPath($path, $value) : $value;
        $key = is_int($segment) ? $segment : var_export($segment, true);
        $lineEnding = str_contains($arraySource, "\r\n") ? "\r\n" : "\n";
        $indent = self::detectArrayIndent($arraySource, $insertAt);
        $innerStart = self::getArrayInnerStartOffset($arraySource);
        $body = trim(substr($arraySource, $innerStart, $insertAt - $innerStart));
        $prefix = $body === '' ? $lineEnding : ',' . $lineEnding;
        $insertion = $prefix . $indent . $key . ' => ' . $itemValue;

        return substr_replace($arraySource, $insertion, $insertAt, 0);
    }

    /**
     * Check if an array key token matches a requested path segment.
     *
     * @param array $token
     * @param string|int $segment
     * @return bool
     */
    private static function arrayKeyMatches(array $token, string|int $segment): bool
    {
        if (is_int($segment)) {
            return self::isToken($token, T_LNUMBER) && (int)str_replace('_', '', $token['text']) === $segment;
        }

        if (self::isToken($token, T_CONSTANT_ENCAPSED_STRING)) {
            return self::unquoteTokenString($token['text']) === $segment;
        }

        return self::isToken($token, T_STRING) && $token['text'] === $segment;
    }

    /**
     * Find the exact source range of a top-level config value.
     *
     * @param string $key
     * @return array|null
     */
    private function findConfigValueRange(string $key): ?array
    {
        $tokens = self::tokenizeWithOffsets($this->data);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (!self::isToken($tokens[$i], T_VARIABLE, '$config')) {
                continue;
            }

            $i = self::nextMeaningfulTokenIndex($tokens, $i + 1);
            if ($i === null || $tokens[$i]['text'] !== '[') {
                continue;
            }

            $i = self::nextMeaningfulTokenIndex($tokens, $i + 1);
            if ($i === null || !self::isToken($tokens[$i], T_CONSTANT_ENCAPSED_STRING)) {
                continue;
            }

            if (self::unquoteTokenString($tokens[$i]['text']) !== $key) {
                continue;
            }

            $i = self::nextMeaningfulTokenIndex($tokens, $i + 1);
            if ($i === null || $tokens[$i]['text'] !== ']') {
                continue;
            }

            $i = self::nextMeaningfulTokenIndex($tokens, $i + 1);
            if ($i === null || $tokens[$i]['text'] !== '=') {
                continue;
            }

            $valueStartIndex = self::nextMeaningfulTokenIndex($tokens, $i + 1);
            if ($valueStartIndex === null) {
                return null;
            }

            $valueEnd = self::findExpressionEndOffset($tokens, $valueStartIndex);
            if ($valueEnd === null) {
                return null;
            }

            return [
                'valueStart' => $tokens[$valueStartIndex]['offset'],
                'valueEnd' => $valueEnd,
            ];
        }

        return null;
    }

    /**
     * Find the exact source range of an array item value.
     *
     * @param string $arraySource
     * @param string $key
     * @return array|null
     */
    private function findArrayValueRange(string $arraySource, string $key): ?array
    {
        $tokens = self::tokenizeWithOffsets('<?php ' . $arraySource);
        $baseOffset = strlen('<?php ');
        $count = count($tokens);
        $depth = 0;

        for ($i = 0; $i < $count; $i++) {
            $text = $tokens[$i]['text'];

            if ($text === '[' || $text === '(') {
                $depth++;
                continue;
            }

            if ($text === ']' || $text === ')') {
                $depth--;
                continue;
            }

            if ($depth !== 1 || !self::isToken($tokens[$i], T_CONSTANT_ENCAPSED_STRING)) {
                continue;
            }

            if (self::unquoteTokenString($text) !== $key) {
                continue;
            }

            $arrow = self::nextMeaningfulTokenIndex($tokens, $i + 1);
            if ($arrow === null || !self::isToken($tokens[$arrow], T_DOUBLE_ARROW)) {
                continue;
            }

            $valueStartIndex = self::nextMeaningfulTokenIndex($tokens, $arrow + 1);
            if ($valueStartIndex === null) {
                return null;
            }

            $valueEnd = self::findArrayItemEndOffset($tokens, $valueStartIndex, 1);
            if ($valueEnd === null) {
                return null;
            }

            return [
                'valueStart' => $tokens[$valueStartIndex]['offset'] - $baseOffset,
                'valueEnd' => $valueEnd - $baseOffset,
            ];
        }

        return null;
    }

    /**
     * Find where a new item can be inserted before the closing array token.
     *
     * @param string $arraySource
     * @return int|null
     */
    private function findArrayInsertOffset(string $arraySource): ?int
    {
        $tokens = self::tokenizeWithOffsets('<?php ' . $arraySource);
        $baseOffset = strlen('<?php ');
        $depth = 0;

        foreach ($tokens as $token) {
            if ($token['text'] === '[' || $token['text'] === '(') {
                $depth++;
                continue;
            }

            if ($token['text'] === ']' || $token['text'] === ')') {
                if ($depth === 1) {
                    return $token['offset'] - $baseOffset;
                }
                $depth--;
            }
        }

        return null;
    }

    /**
     * Get the offset where array items start.
     *
     * @param string $arraySource
     * @return int
     */
    private static function getArrayInnerStartOffset(string $arraySource): int
    {
        $tokens = self::tokenizeWithOffsets('<?php ' . $arraySource);
        $baseOffset = strlen('<?php ');

        foreach ($tokens as $token) {
            if ($token['text'] === '[' || $token['text'] === '(') {
                return $token['end'] - $baseOffset;
            }
        }

        return 0;
    }

    /**
     * Detect indentation for a new item inserted before the closing array token.
     *
     * @param string $arraySource
     * @param int $insertAt
     * @return string
     */
    private static function detectArrayIndent(string $arraySource, int $insertAt): string
    {
        $beforeClose = substr($arraySource, 0, $insertAt);

        if (preg_match('/\R([ \t]*)[^\r\n]*\R[ \t]*$/', $beforeClose, $matches)) {
            return $matches[1];
        }

        return '    ';
    }



    /**
     * Validate raw config source without regenerating its structure.
     * Comments, spacing, arrays, and unrelated formatting remain untouched.
     *
     * @param string $source
     * @return string
     */
    public static function validateSource(string $source): string
    {
        $source = self::normalizeSource($source);
        $tokens = token_get_all($source);
        $statements = self::extractSourceStatements($tokens);

        foreach ($statements as $statement) {
            $code = trim($statement);

            if ($code === '') {
                continue;
            }

            $assignment = self::parseConfigAssignment($code);
            $expression = $assignment['expression'];

            self::validateSourceExpression($expression);

            if ($assignment['isRoot'] && !self::expressionStartsWithArray($expression)) {
                throw new InvalidArgumentException('Config root must be an array.');
            }
        }

        return $source;
    }

    /**
     * Backward-compatible alias for source validation.
     *
     * @param string $source
     * @return string
     */
    public static function patchSource(string $source): string
    {
        return self::validateSource($source);
    }

    /**
     * Normalize source before token parsing.
     *
     * @param string $source
     * @return string
     */
    private static function normalizeSource(string $source): string
    {
        $source = preg_replace('/^\xEF\xBB\xBF/', '', $source);
        $source = preg_replace('/^\s*&lt;\?php/', '<?php', $source, 1);

        return $source;
    }

    /**
     * Parse a tokenized config assignment statement and return its value expression.
     *
     * @param string $statement
     * @return array
     */
    private static function parseConfigAssignment(string $statement): array
    {
        $tokens = self::tokenizeWithOffsets('<?php ' . $statement);
        $baseOffset = strlen('<?php ');
        $index = self::nextMeaningfulTokenIndex($tokens, 0);

        if ($index === null || !self::isToken($tokens[$index], T_VARIABLE, '$config')) {
            throw new InvalidArgumentException('Only $config array assignments are allowed.');
        }

        $index = self::nextMeaningfulTokenIndex($tokens, $index + 1);
        $isRoot = true;

        if ($index !== null && $tokens[$index]['text'] === '[') {
            $isRoot = false;
            $index = self::nextMeaningfulTokenIndex($tokens, $index + 1);
            if ($index === null || !self::isToken($tokens[$index], T_CONSTANT_ENCAPSED_STRING)) {
                throw new InvalidArgumentException('Config keys must be literal strings.');
            }

            $index = self::nextMeaningfulTokenIndex($tokens, $index + 1);
            if ($index === null || $tokens[$index]['text'] !== ']') {
                throw new InvalidArgumentException('Invalid config key syntax.');
            }

            $index = self::nextMeaningfulTokenIndex($tokens, $index + 1);
        }

        if ($index === null || $tokens[$index]['text'] !== '=') {
            throw new InvalidArgumentException('Only $config array assignments are allowed.');
        }

        $valueStart = self::nextMeaningfulTokenIndex($tokens, $index + 1);
        if ($valueStart === null) {
            throw new InvalidArgumentException('Missing config value.');
        }

        $valueEnd = self::findExpressionEndOffset($tokens, $valueStart);
        if ($valueEnd === null) {
            throw new InvalidArgumentException('Invalid or unterminated config statement.');
        }

        return [
            'isRoot' => $isRoot,
            'expression' => substr($statement, $tokens[$valueStart]['offset'] - $baseOffset, $valueEnd - $tokens[$valueStart]['offset']),
        ];
    }

    /**
     * Check whether an expression starts with an array literal.
     *
     * @param string $expression
     * @return bool
     */
    private static function expressionStartsWithArray(string $expression): bool
    {
        $tokens = token_get_all('<?php ' . $expression);
        $index = self::nextRawMeaningfulToken($tokens, 1, count($tokens));

        return $index === '[' || strtolower((string)$index) === 'array';
    }

    /**
     * Extract executable config statements while ignoring comments.
     *
     * @param array $tokens
     * @return array
     */
    private static function extractSourceStatements(array $tokens): array
    {
        $statements = [];
        $current = '';
        $depth = 0;

        foreach ($tokens as $token) {
            if (is_array($token)) {
                [$id, $text] = $token;

                if (in_array($id, [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                if (in_array($id, [T_OPEN_TAG, T_CLOSE_TAG, T_WHITESPACE], true) && trim($current) === '') {
                    continue;
                }

                self::assertAllowedSourceToken($id, $text);
                $current .= $text;
                continue;
            }

            if ($token === '[' || $token === '(') {
                $depth++;
            } elseif ($token === ']' || $token === ')') {
                $depth--;
            }

            self::assertAllowedSourceCharacter($token);
            $current .= $token;

            if ($token === ';' && $depth === 0) {
                $statements[] = $current;
                $current = '';
            }
        }

        if (trim($current) !== '') {
            throw new InvalidArgumentException('Invalid or unterminated config statement.');
        }

        return $statements;
    }

    /**
     * Validate that an expression only contains literals, arrays, and $config references.
     *
     * @param string $expression
     */
    private static function validateSourceExpression(string $expression): void
    {
        $tokens = token_get_all('<?php ' . $expression);
        $count = count($tokens);

        foreach ($tokens as $index => $token) {
            if (is_array($token)) {
                [$id, $text] = $token;
                if ($id === T_OPEN_TAG || in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_LNUMBER, T_DNUMBER, T_CONSTANT_ENCAPSED_STRING, T_ARRAY, T_DOUBLE_ARROW, T_DOUBLE_COLON], true)) {
                    continue;
                }
                if ($id === T_STRING && in_array(strtolower($text), ['true', 'false', 'null'], true)) {
                    continue;
                }
                if ($id === T_STRING) {
                    $next = self::nextRawMeaningfulToken($tokens, $index + 1, $count);
                    if ($next === '(') {
                        throw new InvalidArgumentException('Function calls are not allowed in config values.');
                    }
                    continue;
                }
                if ($id === T_VARIABLE && $text === '$config') {
                    continue;
                }

                throw new InvalidArgumentException('Config values may only contain literals and arrays.');
            }

            self::assertAllowedSourceCharacter($token);
        }
    }

    /**
     * Ensure only safe tokens appear in config source.
     *
     * @param int $id
     * @param string $text
     */
    private static function assertAllowedSourceToken(int $id, string $text): void
    {
        $allowed = [T_VARIABLE, T_WHITESPACE, T_LNUMBER, T_DNUMBER, T_CONSTANT_ENCAPSED_STRING, T_ARRAY, T_DOUBLE_ARROW, T_DOUBLE_COLON, T_STRING];

        if (!in_array($id, $allowed, true)) {
            throw new InvalidArgumentException('Unsupported PHP token in config source.');
        }

        if ($id === T_VARIABLE && $text !== '$config') {
            throw new InvalidArgumentException('Only the $config variable is allowed.');
        }

    }

    /**
     * Ensure only safe single-character tokens appear in config source.
     *
     * @param string $character
     */
    private static function assertAllowedSourceCharacter(string $character): void
    {
        if (!in_array($character, ['[', ']', '(', ')', '=', '>', ',', ';', '.', '-', '+'], true)) {
            throw new InvalidArgumentException('Unsupported character in config source.');
        }
    }


    /**
     * Tokenize source while preserving byte offsets for precise patches.
     *
     * @param string $source
     * @return array
     */
    private static function tokenizeWithOffsets(string $source): array
    {
        $tokens = [];
        $offset = 0;

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                [$id, $text] = $token;
            } else {
                $id = null;
                $text = $token;
            }

            $tokens[] = [
                'id' => $id,
                'text' => $text,
                'offset' => $offset,
                'end' => $offset + strlen($text),
            ];

            $offset += strlen($text);
        }

        return $tokens;
    }

    /**
     * Find the next token that is not whitespace or comment.
     *
     * @param array $tokens
     * @param int $start
     * @return int|null
     */
    private static function nextMeaningfulTokenIndex(array $tokens, int $start): ?int
    {
        $count = count($tokens);

        for ($i = $start; $i < $count; $i++) {
            if (!self::isIgnorableToken($tokens[$i])) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Find the next non-whitespace/comment token text in a raw token_get_all() list.
     *
     * @param array $tokens
     * @param int $start
     * @param int $count
     * @return string|null
     */
    private static function nextRawMeaningfulToken(array $tokens, int $start, int $count): ?string
    {
        for ($i = $start; $i < $count; $i++) {
            if (is_array($tokens[$i])) {
                if (in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                return $tokens[$i][1];
            }

            return $tokens[$i];
        }

        return null;
    }

    /**
     * Check whether a token matches an id and optional text.
     *
     * @param array $token
     * @param int $id
     * @param string|null $text
     * @return bool
     */
    private static function isToken(array $token, int $id, ?string $text = null): bool
    {
        if ($token['id'] !== $id) {
            return false;
        }

        return $text === null || $token['text'] === $text;
    }

    /**
     * Check whether a token can be ignored when locating syntax ranges.
     *
     * @param array $token
     * @return bool
     */
    private static function isIgnorableToken(array $token): bool
    {
        return in_array($token['id'], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG], true);
    }

    /**
     * Decode a quoted PHP string token for key comparison.
     *
     * @param string $token
     * @return string
     */
    private static function unquoteTokenString(string $token): string
    {
        return stripcslashes(substr($token, 1, -1));
    }

    /**
     * Find the end offset of an expression before its terminating semicolon.
     *
     * @param array $tokens
     * @param int $start
     * @return int|null
     */
    private static function findExpressionEndOffset(array $tokens, int $start): ?int
    {
        $depth = 0;
        $last = null;
        $count = count($tokens);

        for ($i = $start; $i < $count; $i++) {
            $text = $tokens[$i]['text'];

            if ($text === '[' || $text === '(') {
                $depth++;
            } elseif ($text === ']' || $text === ')') {
                $depth--;
            }

            if ($text === ';' && $depth === 0) {
                return $last ? $last['end'] : $tokens[$i]['offset'];
            }

            if (!self::isIgnorableToken($tokens[$i])) {
                $last = $tokens[$i];
            }
        }

        return null;
    }

    /**
     * Find the end offset of an array item value before comma or closing array token.
     *
     * @param array $tokens
     * @param int $start
     * @param int $itemDepth
     * @return int|null
     */
    private static function findArrayItemEndOffset(array $tokens, int $start, int $itemDepth): ?int
    {
        $depth = $itemDepth;
        $last = null;
        $count = count($tokens);

        for ($i = $start; $i < $count; $i++) {
            $text = $tokens[$i]['text'];

            if (($text === ',' && $depth === $itemDepth) || (($text === ']' || $text === ')') && $depth === $itemDepth)) {
                return $last ? $last['end'] : $tokens[$i]['offset'];
            }

            if ($text === '[' || $text === '(') {
                $depth++;
            } elseif ($text === ']' || $text === ')') {
                $depth--;
            }

            if (!self::isIgnorableToken($tokens[$i])) {
                $last = $tokens[$i];
            }
        }

        return null;
    }

}
