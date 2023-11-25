# Extended DDEV

This is a very opinionated CLI wrapped around DDEV to handle repetitive processes I do all day every day as a maintainer of Silverstripe CMS.

See https://ddev.readthedocs.io for the official DDEV documentation.

## Usage

Add `bin/eddev` to your path first.

```bash
# get the version of DDEV you have installed.
eddev -V # or eddev help -V... but -V or --version with any other command won't work, since those can be flags in DDEV commands

# list all available commands - lists all DDEV commands and my custom ones.
eddev # or eddev list-commands
```
