# Step 12: Validation

---

## 1. 🎯 Purpose (WHY)

**Validation** ensures incoming data meets requirements before you process it. Without it, you'd scatter `if (!$email) ...` checks throughout your controllers — inconsistent, verbose, and untestable.

With a validator:
```php
$validator = Validator::make($request->all(), [
    'name'  => 'required|min:3|max:255',
    'email' => 'required|email',
    'age'   => 'required|integer|min:18',
]);

if ($validator->fails()) {
    return $this->json($validator->errors(), 422);
}
```

**Laravel equivalent:** `Illuminate\Validation\Validator` (massively complex, we implement the core rules only)

---

## 2. 🧠 Concept (WHAT)

The validator takes **data** (array), **rules** (array of pipe-separated strings), and checks each field against each rule:

```
rules = [
    'email' => 'required|email',
    'age'   => 'required|integer|min:18',
]

For 'email':
  → check 'required'  → passes if value is non-empty
  → check 'email'     → passes if valid email format

For 'age':
  → check 'required'  → passes
  → check 'integer'   → passes if is_numeric and whole number
  → check 'min:18'    → passes if value >= 18
```

Failures are collected per-field as **error messages**.

---

## 3. 🏗 Implementation (HOW)

### File: `laravel-clone/src/Validation/Validator.php`

```php
<?php

namespace Framework\Validation;

class Validator
{
    protected array $data;
    protected array $rules;
    protected array $errors = [];

    /**
     * Custom error messages per field|rule.
     * Example: ['email.required' => 'Please provide your email.']
     */
    protected array $messages;

    public function __construct(array $data, array $rules, array $messages = [])
    {
        $this->data     = $data;
        $this->rules    = $rules;
        $this->messages = $messages;
    }

    /**
     * Static factory — matches Laravel's Validator::make() API.
     */
    public static function make(array $data, array $rules, array $messages = []): static
    {
        $instance = new static($data, $rules, $messages);
        $instance->validate();

        return $instance;
    }

    /**
     * Run validation and collect errors.
     */
    public function validate(): void
    {
        $this->errors = [];

        foreach ($this->rules as $field => $ruleString) {
            $rules = explode('|', $ruleString);
            $value = $this->data[$field] ?? null;

            foreach ($rules as $rule) {
                [$ruleName, $param] = $this->parseRule($rule);

                $passed = $this->check($ruleName, $field, $value, $param);

                if (! $passed) {
                    // First failure per field stops further checks
                    // (Laravel's "bail" is default only for some rules — we keep it simple)
                    $this->addError($field, $ruleName, $param);
                    break;
                }
            }
        }
    }

    /**
     * Parse 'min:3' → ['min', '3'], 'required' → ['required', null]
     */
    protected function parseRule(string $rule): array
    {
        if (str_contains($rule, ':')) {
            [$name, $param] = explode(':', $rule, 2);
            return [trim($name), trim($param)];
        }

        return [trim($rule), null];
    }

    /**
     * Run a single rule check. Returns true if passes.
     */
    protected function check(string $rule, string $field, mixed $value, ?string $param): bool
    {
        return match ($rule) {
            'required'  => $this->checkRequired($value),
            'nullable'  => true,  // always passes, skips further rules if null
            'string'    => is_string($value) || $value === null,
            'integer',
            'int'       => filter_var($value, FILTER_VALIDATE_INT) !== false,
            'numeric'   => is_numeric($value),
            'boolean',
            'bool'      => in_array($value, [true, false, 0, 1, '0', '1'], true),
            'email'     => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'url'       => filter_var($value, FILTER_VALIDATE_URL) !== false,
            'min'       => $this->checkMin($value, (int) $param),
            'max'       => $this->checkMax($value, (int) $param),
            'in'        => in_array($value, explode(',', $param ?? '')),
            'not_in'    => ! in_array($value, explode(',', $param ?? '')),
            'confirmed' => $value === ($this->data[$field . '_confirmation'] ?? null),
            'same'      => $value === ($this->data[$param] ?? null),
            'different' => $value !== ($this->data[$param] ?? null),
            'regex'     => preg_match($param, (string) $value) === 1,
            'alpha'     => ctype_alpha((string) $value),
            'alpha_num' => ctype_alnum((string) $value),
            'array'     => is_array($value),
            default     => throw new \InvalidArgumentException("Unknown validation rule: [{$rule}]"),
        };
    }

    /**
     * Check 'required' — value must exist and not be empty.
     */
    protected function checkRequired(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        if (is_array($value) && empty($value)) {
            return false;
        }

        return true;
    }

    /**
     * Check 'min' — for strings: min length; for numbers: min value.
     */
    protected function checkMin(mixed $value, int $min): bool
    {
        if ($value === null) {
            return true; // null handled by 'required'
        }

        if (is_numeric($value)) {
            return (float) $value >= $min;
        }

        return mb_strlen((string) $value) >= $min;
    }

    /**
     * Check 'max' — for strings: max length; for numbers: max value.
     */
    protected function checkMax(mixed $value, int $max): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_numeric($value)) {
            return (float) $value <= $max;
        }

        return mb_strlen((string) $value) <= $max;
    }

    /**
     * Record a validation error.
     */
    protected function addError(string $field, string $rule, ?string $param): void
    {
        // Check for custom message first
        $customKey = "{$field}.{$rule}";

        if (isset($this->messages[$customKey])) {
            $this->errors[$field][] = $this->messages[$customKey];
            return;
        }

        $this->errors[$field][] = $this->buildMessage($field, $rule, $param);
    }

    /**
     * Build a human-readable error message.
     */
    protected function buildMessage(string $field, string $rule, ?string $param): string
    {
        $label = str_replace('_', ' ', $field);

        return match ($rule) {
            'required'  => "The {$label} field is required.",
            'string'    => "The {$label} field must be a string.",
            'integer',
            'int'       => "The {$label} field must be an integer.",
            'numeric'   => "The {$label} field must be a number.",
            'boolean',
            'bool'      => "The {$label} field must be true or false.",
            'email'     => "The {$label} field must be a valid email address.",
            'url'       => "The {$label} field must be a valid URL.",
            'min'       => "The {$label} field must be at least {$param}.",
            'max'       => "The {$label} field must not exceed {$param}.",
            'in'        => "The {$label} field must be one of: {$param}.",
            'not_in'    => "The {$label} field must not be one of: {$param}.",
            'confirmed' => "The {$label} confirmation does not match.",
            'same'      => "The {$label} must match {$param}.",
            'different' => "The {$label} must be different from {$param}.",
            'alpha'     => "The {$label} field must only contain letters.",
            'alpha_num' => "The {$label} field must only contain letters and numbers.",
            'array'     => "The {$label} field must be an array.",
            default     => "The {$label} field failed the {$rule} rule.",
        };
    }

    // ─── Results ──────────────────────────────────────────────────────────────

    /**
     * Returns true if any errors were recorded.
     */
    public function fails(): bool
    {
        return ! empty($this->errors);
    }

    /**
     * Returns true if all rules passed.
     */
    public function passes(): bool
    {
        return empty($this->errors);
    }

    /**
     * Get all errors: ['field' => ['error1', 'error2'], ...]
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Get the first error per field: ['field' => 'error1', ...]
     */
    public function firstErrors(): array
    {
        return array_map(fn ($e) => $e[0], $this->errors);
    }

    /**
     * Get the validated data (only fields that have rules defined).
     */
    public function validated(): array
    {
        return array_intersect_key($this->data, $this->rules);
    }
}
```

### Global helper: add to `laravel-clone/src/helpers.php`

```php
if (! function_exists('validator')) {
    function validator(array $data, array $rules, array $messages = []): \Framework\Validation\Validator
    {
        return \Framework\Validation\Validator::make($data, $rules, $messages);
    }
}
```

### Update base `Controller.php` with `validate()` helper

```php
/**
 * Validate request data. Returns validated data or throws on failure.
 */
protected function validate(Request $request, array $rules, array $messages = []): array
{
    $validator = \Framework\Validation\Validator::make(
        $request->all(),
        $rules,
        $messages
    );

    if ($validator->fails()) {
        if ($request->expectsJson()) {
            throw new \Framework\Validation\ValidationException($validator);
        }

        // For HTML forms — ideally redirect back with errors
        // For simplicity, we throw and let the Kernel render it
        throw new \Framework\Validation\ValidationException($validator);
    }

    return $validator->validated();
}
```

### File: `laravel-clone/src/Validation/ValidationException.php`

```php
<?php

namespace Framework\Validation;

class ValidationException extends \RuntimeException
{
    public function __construct(
        protected Validator $validator
    ) {
        parent::__construct('The given data was invalid.');
    }

    public function errors(): array
    {
        return $this->validator->errors();
    }
}
```

### Updated `Kernel::renderException()` to handle validation errors:

```php
protected function renderException(Request $request, Throwable $e): Response
{
    if ($e instanceof \Framework\Validation\ValidationException) {
        return new Response(
            json_encode(['errors' => $e->errors()]),
            422,
            ['Content-Type' => 'application/json']
        );
    }

    $status  = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
    $debug   = $this->app->make('config')->get('app.debug', false);
    $message = $debug
        ? $e->getMessage() . "\n" . $e->getTraceAsString()
        : 'Server Error';

    return new Response($message, $status);
}
```

---

## 4. 🔗 Integration

```
Controller::store($request)
  → $this->validate($request, ['name' => 'required|min:3'])
      → Validator::make($data, $rules)
          → validate()        ← runs all rules
      → if fails: throw ValidationException
      → Kernel catches it    ← renderException()
      → Response 422 with errors
```

---

## 5. ✅ Usage Example

### In a controller

```php
class UserController extends Controller
{
    public function store(Request $request): Response
    {
        // Validate — throws ValidationException if fails
        $data = $this->validate($request, [
            'name'                  => 'required|string|min:2|max:255',
            'email'                 => 'required|email',
            'password'              => 'required|min:8|confirmed',
            'password_confirmation' => 'required',
            'age'                   => 'required|integer|min:18',
            'role'                  => 'required|in:admin,user,moderator',
        ]);

        // $data only contains validated fields
        $user = $this->createUser($data);

        return $this->json($user, 201);
    }
}
```

### Direct usage

```php
$validator = Validator::make(
    ['email' => 'not-an-email', 'name' => ''],
    ['email' => 'required|email', 'name' => 'required|min:3']
);

if ($validator->fails()) {
    print_r($validator->errors());
    // [
    //   'email' => ['The email field must be a valid email address.'],
    //   'name'  => ['The name field is required.'],
    // ]
}

// Custom messages
$validator = Validator::make($data, $rules, [
    'email.required' => 'We need your email address!',
    'email.email'    => 'That doesn\'t look like an email.',
]);
```

---

## 6. 📌 Key Elements

| Element | Purpose |
|---------|---------|
| `Validator::make()` | Static factory — validates and returns validator |
| `Validator::fails()` | Check if any rule failed |
| `Validator::errors()` | All errors per field |
| `Validator::validated()` | Only validated (rule-defined) fields |
| `parseRule()` | Parses `'min:3'` → `['min', '3']` |
| `check()` | Dispatches to per-rule methods |
| `checkMin()` / `checkMax()` | Handles both string length and numeric comparison |
| `ValidationException` | Thrown on failure, caught by Kernel |
| `Controller::validate()` | Helper — validates or throws |

---

## 7. ⚠️ Simplifications Made

| Laravel | Our Clone | Reason |
|---------|-----------|--------|
| `Validator` has thousands of rules | ~20 core rules | Sufficient for real apps |
| `required_if`, `required_with`, conditional rules | Not implemented | Advanced conditionals |
| Database rules (`unique`, `exists`) | Not implemented | Requires DB integration |
| Array validation (`items.*`) | Not implemented | Nested validation is complex |
| `bail` modifier (stop on first failure) | Always bail per field | Simpler |
| `FormRequest` class | Inline via `$this->validate()` | Same concept, less indirection |
| Error bags (multiple forms) | Single flat error array | Sufficient for most cases |
| `$validator->sometimes()` | Not implemented | Conditional rule application |

---

## 🎉 Framework Complete!

You have now built all 12 core components of a Laravel-inspired framework:

| # | Component | What it does |
|---|-----------|-------------|
| 01 | Entry Point | Routes all HTTP requests to one file |
| 02 | Application | Central object — IS the container |
| 03 | IoC Container | Dependency injection + auto-resolution |
| 04 | Service Providers | Organized service registration |
| 05 | HTTP Kernel | Bootstraps app + orchestrates request |
| 06 | Middleware Pipeline | Onion-layer request processing |
| 07 | Router | URL → handler mapping |
| 08 | Request / Response | Clean HTTP abstractions |
| 09 | Controller | Organized request handling |
| 10 | View Engine | Template rendering |
| 11 | Config & Env | Environment-aware configuration |
| 12 | Validation | Input validation with errors |

---

## 🔜 What to Add Next (Optional)

| Feature | Concepts |
|---------|---------|
| **Session** | PHP sessions, flash messages |
| **Database** | PDO wrapper, QueryBuilder, simple migrations |
| **Authentication** | Session-based auth, middleware guard |
| **Error Pages** | Custom 404/500 views |
| **Logging** | PSR-3, file logger |
| **Route Groups** | Prefix, middleware groups |
| **CSRF Protection** | Token generation, middleware validation |
