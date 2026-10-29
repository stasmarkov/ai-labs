<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Switching servers

The project runs on nginx-fpm by default (`.ddev/config.yaml` is never modified). Switch the web server with the DDEV host command:

```sh
ddev server                 # show the active and supported servers
ddev server apache-fpm      # write the gitignored overlay .ddev/config.zz-server.yaml and restart
ddev server nginx-fpm       # switch back
```

Apache reads `public/.htaccess` on every request, so rewrite and header rules in that file apply to Apache only and are not cached between requests; nginx ignores it.

Check that both servers answer identically. Seed once so both captures see the same data, then capture on each server and compare:

```sh
ddev artisan migrate:fresh --seed
ddev server nginx-fpm
bench/parity.sh capture                       # reads the active server, writes bench/parity/nginx-fpm.txt
ddev server apache-fpm
bench/parity.sh capture                       # writes bench/parity/apache-fpm.txt
bench/parity.sh compare nginx-fpm apache-fpm  # prints "identical" (exit 0) or the differing requests (exit 1)
```

Each snapshot line holds the path, status, media type (charset and other parameters dropped) and body SHA-256. Only the `/up` page has its render time masked before hashing. `capture` writes nothing if a request fails (exit 3) or the `Server` header does not match the active server (exit 4); set `PARITY_INSECURE=1` if the DDEV certificate is not trusted.

To record versions in `BENCHMARKS.md`, enable `/debug/runtime` and run `bench/versions.sh` on each server:

```sh
# set DEBUG_ROUTES=true in .env, then
ddev artisan optimize:clear
bench/versions.sh                             # one markdown row for BENCHMARKS.md
```

The scripts never change `.env`. Set `DEBUG_ROUTES` back to `false` afterwards; it stays `false` in `.env.example` and for benchmarks.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
