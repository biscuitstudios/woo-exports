# WooExports

Scheduled CSV and XLSX exports for WooCommerce.

Built and maintained by [Biscuit Studios](https://biscuitstudios.com/) for our
own client sites. Published because it may be useful to others, not because it
is a supported product. See [Support](#support).

## What it does

Build named export configurations, run them on demand, or have them emailed on a
schedule.

- **Four data sources:** Products, Orders, Customers, Attendees
- **Named configurations,** so a report you set up once can be re-run or
  scheduled without rebuilding it
- **CSV or XLSX output**
- **Scheduled delivery by email,** with the export attached

## Requirements

- WordPress 6.3 or later
- WooCommerce
- PHP 8.2 or later

## Installation

Download the zip from
[Releases](https://github.com/biscuitstudios/woo-exports/releases), then
**Plugins → Add New → Upload Plugin**.

**Install the released zip, not a clone of this repository.** The plugin depends
on PhpSpreadsheet, which is not committed here. The release zip is built by CI
with dependencies installed, so it runs as-is. A plain `git clone` has no
`vendor/` directory and will fatal on activation.

## Building from source

```bash
composer install --no-dev
bash bin/build.sh
```

`--no-dev` matters. Without it you ship PHPUnit and its dependencies inside the
plugin.

## Support

None, in the usual sense. This is published as-is, and we make changes when our
own client work calls for them.

You are welcome to fork it. If you find a genuine security problem, please
report it privately using the **Security** tab on this repository rather than
opening it in public.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

Bundled dependencies keep their own licenses. PhpSpreadsheet is MIT.
