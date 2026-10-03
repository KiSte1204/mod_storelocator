# **Lightweight Store Locator for Joomla (Leaflet.js and OpenStreetMap)**

A fast, highly scalable, and API-cost-free Store Locator module for Joomla 4 and 5+. Built with Leaflet.js, OpenStreetMap, and native Joomla PHP database drivers.

Designed to efficiently handle datasets of 2,000+ global locations without triggering Google Maps API charges or Nominatim rate limits.

# **Key Features**

* **Zero API Costs:** Uses OpenStreetMap for map rendering and geocoding via Nominatim.  
* **Smart Search and Geolocation:** Search by postal code or city, or use native browser GPS ("My Location").  
* **Haversine Distance Calculation:** Instant radius filtering (10 km, 25 km, 50 km) directly in JavaScript.  
* **Direct Google Maps Routing:** One-click navigation link passing clean street address parameters.  
* **Responsive Layout:** CSS Grid layout with sticky map positioning and mobile optimization.  
* **Automated Batch Geocoding:** Includes a CLI/HTTP background cronjob script to process large database imports without hitting API limits.

# **Requirements**

| Requirement | Details |
| :---- | :---- |
| **Joomla Version** | Joomla 4.x or Joomla 5.x (compatible with Joomla 6 readiness standards) |
| **Database Table** | Standard MySQL or MariaDB table containing location data |
| **Extensions Used** | Custom module with PHP execution capability (e.g., Sourcerer or custom native wrapper) |

# **Database Setup (`#__locations`)**

Ensure a database table named `#__locations` (or `ll_locations`) exists in your Joomla database. You can import customer addresses from your CRM (e.g., Salesforce, HubSpot) or CSV exports into this table.

## **Minimal SQL Schema**

CREATE TABLE IF NOT EXISTS \`\#\_\_locations\` (

  \`id\` INT(11) NOT NULL AUTO\_INCREMENT,

  \`name\` VARCHAR(255) NOT NULL,

  \`address\` VARCHAR(255) DEFAULT NULL,

  \`postal\` VARCHAR(20) DEFAULT NULL,

  \`town\` VARCHAR(100) DEFAULT NULL,

  \`country\` VARCHAR(50) DEFAULT NULL,

  \`phone\` VARCHAR(50) DEFAULT NULL,

  \`email\` VARCHAR(100) DEFAULT NULL,

  \`website\` VARCHAR(255) DEFAULT NULL,

  \`latitude\` DECIMAL(10,8) DEFAULT NULL,

  \`longitude\` DECIMAL(11,8) DEFAULT NULL,

  \`published\` TINYINT(1) NOT NULL DEFAULT 1,

  PRIMARY KEY (\`id\`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

# **Module Installation and Deployment**

1. Create a new **Custom Module** in Joomla (or use Sourcerer or a direct module wrapper).  
2. Insert the Store Locator script into the module.  
3. In the Joomla Module settings under **Advanced**:  
   * Set **Module Class** to:  `ll-store-locator` *(include leading space)*.  
   * Set **Module Style** to: `html5` or `Astroid Xhtml` / `Card` *(if using Astroid Framework)*.  
4. Assign the module to your desired page or menu item.

# **Automated Geocoding Script (`geocode_cron.php`)**

Because OpenStreetMap Nominatim enforces a strict 1 request per second Fair-Use Policy, geocoding thousands of addresses directly in the frontend browser is not viable.

The included geocode\_cron.php script automatically fetches non-geocoded records in batches and updates latitude and longitude fields in the background.

## **Setup Instructions**

1. Place `geocode_cron.php` into your Joomla root directory (alongside `configuration.php`).  
2. Open `geocode_cron.php` and set your personal security token:  
   `$cronToken = 'YOUR_SECRET_TOKEN_HERE';`

## 🗺️ Roadmap & Multi-Language Support

- **Current Version (v1.0.0):** The user interface labels and messages are currently provided in **German**.
- **Upcoming Release (v1.1.0):** Native Joomla multi-language integration via language files (`.ini`) and Language Overrides support for English, French, and Dutch is currently under development.

> **Tip for non-German sites:** You can easily adapt the UI strings directly in the `mod_storelocator.php` file until the native `.ini` language overrides release is deployed.

## **Running via Cronjob**

Set up a nightly cronjob on your web server (e.g., executing every night at 02:00 AM):

* **Option A: Server Command Line (PHP CLI)**  
  `php /path/to/joomla/geocode_cron.php`  
* **Option B: Web Request (via Wget or Curl)**  
  `wget -q -O - "YOUR_DOMAIN/geocode_cron.php?token=YOUR_SECRET_TOKEN"`

## 🛡️ License & Attributions

* **Module License:** Distributed under GNU General Public License v2 or later (GPLv2+).
* **Leaflet.js:** Included via unpkg.com CDN. Copyright (c) Vladimir Agafonkin (BSD-2-Clause License).
* **Map Data & Geocoding:** &copy; [OpenStreetMap](https://www.openstreetmap.org/copyright) contributors, Nominatim API.

Distributed under the MIT License. Free to use, modify, and distribute for commercial and non-commercial projects.