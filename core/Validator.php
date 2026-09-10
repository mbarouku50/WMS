<?php
/**
 * WMS - Input validation.
 *
 * Small rule-based validator.  Rules are given as "field" => "rule|rule:arg".
 *
 *      $v = new Validator($_POST);
 *      $v->rules([
 *          'full_name' => 'required|min:3|max:140',
 *          'phone'     => 'required|phone',
 *          'email'     => 'nullable|email',
 *          'price'     => 'required|numeric|min_value:0',
 *      ]);
 *      if (!$v->passes()) { $errors = $v->errors(); }
 */
class Validator
{
    private array $data;
    private array $errors = [];
    private array $labels = [];

    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    /** Friendly field names used in error messages. */
    public function labels(array $labels): self
    {
        $this->labels = $labels;
        return $this;
    }

    /** @param array<string,string> $rules */
    public function rules(array $rules): self
    {
        foreach ($rules as $field => $ruleString) {
            $value    = $this->value($field);
            $ruleList = explode('|', $ruleString);
            $nullable = in_array('nullable', $ruleList, true);

            if ($nullable && ($value === null || $value === '')) {
                continue;
            }

            foreach ($ruleList as $rule) {
                if ($rule === 'nullable') {
                    continue;
                }
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                $this->applyRule($field, $name, $arg, $value);
                if (isset($this->errors[$field])) {
                    break; // one message per field keeps forms readable
                }
            }
        }
        return $this;
    }

    private function applyRule(string $field, string $rule, ?string $arg, mixed $value): void
    {
        $label = $this->labels[$field] ?? ucwords(str_replace('_', ' ', $field));
        $str   = is_scalar($value) ? trim((string)$value) : '';

        switch ($rule) {
            case 'required':
                if ($value === null || $str === '' || (is_array($value) && !$value)) {
                    $this->addError($field, "$label is required.");
                }
                break;

            case 'min':
                if (mb_strlen($str) < (int)$arg) {
                    $this->addError($field, "$label must be at least $arg characters.");
                }
                break;

            case 'max':
                if (mb_strlen($str) > (int)$arg) {
                    $this->addError($field, "$label may not exceed $arg characters.");
                }
                break;

            case 'email':
                if (!filter_var($str, FILTER_VALIDATE_EMAIL)) {
                    $this->addError($field, "$label must be a valid email address.");
                }
                break;

            case 'phone':
                if (!preg_match('/^[0-9+\s()-]{9,20}$/', $str)) {
                    $this->addError($field, "$label must be a valid phone number.");
                }
                break;

            case 'numeric':
                if (!is_numeric($str)) {
                    $this->addError($field, "$label must be a number.");
                }
                break;

            case 'integer':
                if (!preg_match('/^-?\d+$/', $str)) {
                    $this->addError($field, "$label must be a whole number.");
                }
                break;

            case 'min_value':
                if (!is_numeric($str) || (float)$str < (float)$arg) {
                    $this->addError($field, "$label must be $arg or more.");
                }
                break;

            case 'max_value':
                if (!is_numeric($str) || (float)$str > (float)$arg) {
                    $this->addError($field, "$label must be $arg or less.");
                }
                break;

            case 'in':
                $allowed = explode(',', (string)$arg);
                if (!in_array($str, $allowed, true)) {
                    $this->addError($field, "$label is not a valid choice.");
                }
                break;

            case 'alpha_num':
                if (!preg_match('/^[A-Za-z0-9]+$/', $str)) {
                    $this->addError($field, "$label may only contain letters and numbers.");
                }
                break;

            case 'username':
                if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $str)) {
                    $this->addError($field, "$label may only contain letters, numbers, dots, dashes and underscores.");
                }
                break;

            case 'slug':
                if (!preg_match('/^[A-Za-z0-9_-]+$/', $str)) {
                    $this->addError($field, "$label may only contain letters, numbers, dashes and underscores.");
                }
                break;

            case 'ip':
                if (!filter_var($str, FILTER_VALIDATE_IP)) {
                    $this->addError($field, "$label must be a valid IP address.");
                }
                break;

            case 'mac':
                // Accepts the formats real equipment prints - AA:BB:CC:11:22:33,
                // AA-BB-..., aabb.ccdd.eeff and bare hex. normalise_mac() then
                // stores every one of them in the same canonical shape.
                if (!self::isMac($str)) {
                    $this->addError($field, "$label must be a valid MAC address, for example AA:BB:CC:11:22:33.");
                }
                break;

            case 'host':
                // A router's API address may be an IPv4 address, an IPv6
                // address or a DNS hostname. Anything else is refused rather
                // than handed to the socket layer.
                if (!self::isHost($str)) {
                    $this->addError($field, "$label must be an IP address or a hostname.");
                }
                break;

            case 'date':
                if (strtotime($str) === false) {
                    $this->addError($field, "$label must be a valid date.");
                }
                break;

            case 'matches':
                if ($str !== (string)($this->value((string)$arg) ?? '')) {
                    $this->addError($field, "$label does not match.");
                }
                break;

            case 'password':
                if (mb_strlen($str) < 8 || !preg_match('/[A-Za-z]/', $str) || !preg_match('/\d/', $str)) {
                    $this->addError($field, "$label must be at least 8 characters and include a letter and a number.");
                }
                break;
        }
    }

    public function addError(string $field, string $message): void
    {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = $message;
        }
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    public function fails(): bool
    {
        return !$this->passes();
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): ?string
    {
        return $this->errors ? reset($this->errors) : null;
    }

    public function value(string $field): mixed
    {
        return $this->data[$field] ?? null;
    }

    /* ---------------------------------------------------------- sanitising */

    public static function string(mixed $value, int $maxLength = 255): string
    {
        $value = is_scalar($value) ? trim((string)$value) : '';
        return mb_substr($value, 0, $maxLength);
    }

    public static function int(mixed $value, int $default = 0): int
    {
        return is_numeric($value) ? (int)$value : $default;
    }

    public static function float(mixed $value, float $default = 0.0): float
    {
        return is_numeric($value) ? (float)$value : $default;
    }

    public static function bool(mixed $value): int
    {
        return in_array((string)$value, ['1', 'on', 'true', 'yes'], true) ? 1 : 0;
    }

    /** Normalises a Tanzanian phone number to 255XXXXXXXXX where possible. */
    public static function normalisePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($digits, '255')) {
            return $digits;
        }
        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            return '255' . substr($digits, 1);
        }
        if (strlen($digits) === 9) {
            return '255' . $digits;
        }
        return $digits;
    }

    /* ------------------------------------------------------------ network */

    /**
     * True when the value is a usable network address: IPv4, IPv6 or a DNS
     * hostname. Used for router API addresses and AP management addresses.
     */
    public static function isHost(string $value): bool
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 253) {
            return false;
        }

        // Bracketed IPv6, as it appears in URLs: [2001:db8::1]
        if (str_starts_with($value, '[') && str_ends_with($value, ']')) {
            $value = substr($value, 1, -1);
        }
        if (filter_var($value, FILTER_VALIDATE_IP)) {
            return true;
        }

        // Hostname: dot-separated labels of letters, digits and inner dashes.
        return (bool)preg_match(
            '/^(?=.{1,253}$)([A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/',
            $value
        );
    }

    /** True when the value carries exactly 12 hex digits in a known layout. */
    public static function isMac(string $value): bool
    {
        $value = trim($value);
        return (bool)preg_match('/^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/', $value)
            || (bool)preg_match('/^[0-9A-Fa-f]{4}(\.[0-9A-Fa-f]{4}){2}$/', $value)
            || (bool)preg_match('/^[0-9A-Fa-f]{12}$/', $value);
    }
}
