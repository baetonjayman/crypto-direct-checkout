# Crypto Direct Checkout

A WooCommerce payment plugin that lets customers pay with **USDT (TRC20)**, **USDT (ERC20)** and **Bitcoin** straight into the store owner's own wallet. No payment company holds the money in between.

The store owner pastes a wallet address. The plugin gives each order a unique amount, then watches the public blockchain and marks the order paid when the payment arrives.

## Install
Download the latest zip from [`update/`](update/), then in WordPress go to **Plugins → Add New → Upload Plugin**.

## Updates
Installed copies update themselves from this repository. Every release is signed with the author's offline key, and the plugin refuses any update whose signature doesn't match.

## Status
Early release. Tested on a local WordPress + WooCommerce site with real blockchain data; not yet proven on a live store.

License: GPL-2.0-or-later. Includes qrcode-generator (MIT) by Kazuhiko Arase.
