# WordPress Plugin Boilerplate CLI

## Overview

WordPress Plugin Boilerplate CLI is a command-line interface tool designed to expedite the creation of new WordPress plugins using the WordPress Plugin Boilerplate structure from [https://wppb.me](https://wppb.me). This tool automates the setup process, enabling developers to rapidly scaffold new plugins that adhere to best practices and maintain a standardized structure.

## Key Features

- Generates new plugins based on the WordPress Plugin Boilerplate (https://github.com/DevinVinson/WordPress-Plugin-Boilerplate)
- Customizes plugins with specific details (name, description, author information, etc.)
- Adheres to WordPress coding standards and best practices
- Significantly reduces initial plugin setup time

## Installation

To install the WordPress Plugin Boilerplate CLI, ensure that Composer is installed on your system. Then, install the package globally using the following command:

```
composer global require tmeister/wppb-cli
```

## Usage

After installation, initiate the CLI tool by executing:

```
wppb new
```

This command will prompt you for various plugin details, including:

- Plugin Name
- Plugin Slug
- Plugin Description
- Author Name
- Author Email
- Author URL

Upon providing the necessary information, the tool will generate a new plugin structure in your current directory.

## Configuration (Optional)

Create a configuration file named `.wppb` in your home directory to set default values for the plugin creation process. Example:

```
author=Enrique Chavez
authorEmail=me@enriquechavez.co
authorUrl=https://enriquechavez.co
```

## Tested On

- [x] Mac
- [ ] Linux
- [ ] Windows

## Contributing

We welcome contributions to this project. Please feel free to submit a Pull Request for any improvements or bug fixes.

## License

This project is licensed under the GPL v2 or later.

## Acknowledgments

This project is based on the [WordPress Plugin Boilerplate Generator](https://wppb.me) by Enrique Chavez (Tmeister).
