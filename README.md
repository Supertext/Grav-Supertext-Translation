# Supertext Translation for Grav

Translate Grav pages into your site's other languages with [Supertext](https://www.supertext.com) AI translation, straight from the page editor of Grav 2's Admin2.

- A **Supertext** button in the page editor toolbar opens a panel listing every site language and its translation state.
- One click translates the page into several languages at once: content, title, menu label and meta description.
- Markdown formatting, links, code, images, Twig and shortcodes come through intact.
- New translations are created unpublished for review; translations edited by hand are never replaced without asking.

![The Supertext panel in the Grav page editor, after translating into German and French](docs/images/panel-after.png)

Requires Grav 2.0+ with Admin2 and the API plugin, and a Supertext API key.

## Documentation

- [Installation and configuration](docs/INSTALLATION.md): for administrators
- [User guide](docs/USER_GUIDE.md): for editors
- [Developer guide](docs/DEVELOPER.md): architecture, Supertext API protocol, tests, demo, releasing

## Demo

A live demo runs at https://grav-production.up.railway.app (admin at `/admin`). Accounts are provided by Supertext.

## License

MIT, see [LICENSE](LICENSE). Part of Supertext's translation plugins for open source CMS.
