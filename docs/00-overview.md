# Laravel Clone — Learning Framework Guide

> Build a minimal but functional PHP framework inspired by Laravel 13.
> Focus: **clarity, structure, reasoning** — not production completeness.

---

## 🗺 Architecture Map

```
HTTP Request
     │
     ▼
public/index.php              ← Step 01: Entry Point
     │
     ▼
Application (Container)       ← Steps 02–03: Container + Application
     │
     ├── Request / Response   ← Step 04: HTTP abstractions
     │
     ▼
HttpKernel                    ← Step 05: Orchestrator
     │
     ├── Router               ← Step 06: URL matching
     │
     ├── Pipeline             ← Step 07: Middleware onion
     │
     ├── ServiceProviders     ← Step 08: Organized registration
     │
     ▼
Controller                    ← Step 09: Action handling
     │
     ▼
View                          ← Step 10: Template rendering
     │
     ▼
Config / Env                  ← Step 11: Configuration
     │
     ▼
Validation                    ← Step 12: Input validation
```

---

## 📚 Steps Index

| Step | Name | Key Problem Solved | Laravel Equivalent |
|------|------|-------------------|-------------------|
| [01](./01-entry-point.md) | Entry Point | Where do all HTTP requests go? | `public/index.php` |
| [02](./02-container.md) | IoC Container | How do objects find their dependencies? | `Illuminate\Container\Container` |
| [03](./03-application.md) | Application | What is the central hub of the framework? | `Illuminate\Foundation\Application` |
| [04](./04-request-response.md) | Request & Response | How do we represent HTTP cleanly? | `Illuminate\Http\Request/Response` |
| [05](./05-http-kernel.md) | HTTP Kernel | What orchestrates the full request lifecycle? | `Illuminate\Foundation\Http\Kernel` |
| [06](./06-router.md) | Router | How does a URL map to a handler? | `Illuminate\Routing\Router` |
| [07](./07-pipeline.md) | Middleware Pipeline | How do cross-cutting concerns wrap a request? | `Illuminate\Pipeline\Pipeline` |
| [08](./08-service-providers.md) | Service Providers | Where does service registration code live? | `Illuminate\Support\ServiceProvider` |
| [09](./09-controller.md) | Controller | How are related actions grouped? | `Illuminate\Routing\Controller` |
| [10](./10-view-engine.md) | View Engine | How is HTML separated from logic? | `Illuminate\View\View` |
| [11](./11-config-env.md) | Config & Env | How does config change per environment? | `Illuminate\Config\Repository` |
| [12](./12-validation.md) | Validation | How is input validated consistently? | `Illuminate\Validation\Validator` |

---

## 📐 Step Format

Each step follows this exact structure:

1. 🚩 **The Problem** — A concrete real-world problem the step solves
2. 🔍 **Why Naive Solutions Fail** — Why the obvious fix breaks at scale
3. 💡 **The Solution** — The architectural pattern derived from the problem
4. 🏗 **Implementation** — Full working code, no placeholders
5. ✅ **Verify** — Exact command + expected output to confirm it works
6. 📌 **What We Built** — Summary of files and key components
7. ⚠️ **Simplifications** — What was simplified vs real Laravel

---

## 🏗 Target Directory Structure (End State)

```
laravel-clone/
├── app/
│   ├── Controllers/
│   │   └── HomeController.php
│   └── Providers/
│       └── AppServiceProvider.php
├── bootstrap/
│   └── app.php
├── config/
│   └── app.php
├── public/
│   └── index.php           ← Grows across steps, finalized in Step 05
├── resources/
│   └── views/
│       └── home.php
├── routes/
│   └── web.php
├── src/
│   ├── Container/
│   │   └── Container.php   ← Step 02
│   ├── Foundation/
│   │   └── Application.php ← Step 03
│   ├── Http/
│   │   ├── Request.php     ← Step 04
│   │   ├── Response.php    ← Step 04
│   │   └── Kernel.php      ← Step 05
│   ├── Pipeline/
│   │   └── Pipeline.php    ← Step 07
│   ├── Routing/
│   │   ├── Router.php      ← Step 06
│   │   └── Route.php       ← Step 06
│   ├── Support/
│   │   └── ServiceProvider.php ← Step 08
│   ├── View/
│   │   └── ViewFactory.php ← Step 10
│   ├── Config/
│   │   └── Repository.php  ← Step 11
│   ├── Validation/
│   │   └── Validator.php   ← Step 12
│   └── helpers.php         ← Step 10
└── composer.json
```

---

## ⚠️ What We Deliberately Exclude

| Laravel Feature | Why We Exclude |
|----------------|----------------|
| Eloquent ORM | Requires its own framework; use PDO directly |
| Events / Broadcasting | Not part of the HTTP lifecycle |
| Queue / Jobs | Background processing is out of scope |
| Artisan Console | CLI is a separate concern |
| Facades | Static proxies obscure what's happening |
| Blade directives | Plain PHP templates are clearer for learning |
| Auth system | Complex; builds on primitives you learn here |

---

## 🚀 Getting Started

```bash
mkdir laravel-clone && cd laravel-clone
```

Then follow the steps in order. Each one builds on the last, and each one can be verified before moving to the next.

**First step:** [01 — Entry Point →](./01-entry-point.md)
