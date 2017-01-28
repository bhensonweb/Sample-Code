# Coding Example
This repository is for displaying examples of some of my code in PHP, JS, etc.

## PHP

"php/mvc/models/"
These are some examples of models I have created.

"analytics.php"
I created from scratch a system to track analytics-type data based on how users used the site. It tracks detailed data about the orders placed on the site. In this environment, online orders are not always fulfilled by the supplier for various reasons. 

The main purpose of this data is to allow the software to get an idea of how likely an order is to be fulfilled by the supplier and then pass that information on to the user placing the order. To do this it displays a fulfillment-rating based on this and other data. The user has multiple suppliers to choose from based on many factors.

This aso consolidates per-transaction records into days, then months, then years.

"stores.php"
This model has all the functions needed to handle all database interactions related to Stores which are an entity in the software that this is from.

