wp-migrate
=========


---

**NOTE:** This is the first working version of this package and it's had only minimal testing so far. Use on test WordPress installations and ensure that it works as you expect before using on live data. Input and pull requests are welcome.

---

`wp-migrate` is a command-line utility for migrating data (database and uploaded files) from one WordPress installation to another. It transforms URL's in the database searching and safely replacing URL's in a variety of common locations.

`wp-migrate` will allow you to retrieve data from any of the hosts you have configured, load the data into a temporary database for processing, transform the data in the database, export the data and deliver the data to a destination host. These steps can be run automatically in sequence or individually as needed. 

Be aware that when `wp-migrate` is running it will create files in the directory in which you call it from. This is by design so that you can have easy access to the files it creates as it completes each step.


Installation
------------

To get setup, first, you need to place the `wp-migrate` folder and contents somewhere on your computer. You will then need to install the dependencies with [Composer](https://getcomposer.org/):

    composer install


Finally, you need to create a symbolic link pointing to wherever you have have located the `wp-migrate/wp-migrate.php` script. You can create a symbolic link in your `~/bin` folder with the following command

    ln -s /path/to/wp-migrate/wp-migrate.php ~/bin/wp-migrate


Configuration
-------------

`wp-migrate` relies on a config file specifying available hosts. The config file needs to be located in one of the following locations. 

* In the current working directory, `./wp-migrate.json`
* In your home directory, `~/.wp-migrate.json` (note the extra dot)

The config file is in JSON format. At it's most basic, it's should be an object containing the attribute hosts which itself is a an object where each attribute-name is a name of a host with the value being an object representing the information about that host.

    {
    	'hosts': {
    		'host1': {
    			'method': 'ssh',
    			'domain-name': 'www.host1.org',
    			'ssh-host': 'www.host1.org',
    			'ssh-username': 'ssh-username',
    			'ssh-password' => 'ssh-password',
    			'wp-path' => '/path/to/wp/installation',
    		},
    		'host2': {
    			'method': 'ssh',
    			'domain-name': 'www.host2.org',
    			'ssh-host': 'www.host2.org',
    			'ssh-username': 'ssh-username',
    			'ssh-password' => 'ssh-password',
    			'wp-path' => '/path/to/wp/installation',
    		},
    		'host3': {
    			'method': 'local',
    			'domain-name': 'www.host2.org',
    			'wp-path' => '/path/to/wp/installation',
    		}
    	},
    	'temp_db': {
    		'hostname': 'hostname',
    		'username': 'username',
    		'password': 'password',
    		'database_name': 'database_name',
    	}

    }

You can simply rename the included `wp-migration-sample.json` to get started.


Running
-------

There are three commands that can be used independently or run in sequence with a fourth command.

1. Retrieve: `wp-migrate retrieve <origin-host>`
2. Retrieve: `wp-migrate retrieve <destination-host>`
2. Transform: `wp-migrate transform <origin-host> <destination-host>`
3. Deliver: `wp-migrate deliver <destination-host>`
4. Auto: `wp-migrate auto <origin-host> <destination-host>`


### Retrieve

    wp-migrate retrieve <origin-host>

This command will retrieve an MySQL dump of the database an a gzipped tar of the uploads directory for the host specified by <origin-host>. 'origin-host' must be an attribute in the `hosts` object in the configuration file.

If the method for the host is `ssh`, then `wp-migrate` will connect via ssh, locate the wp-config file and then run a mysql dump (using the connection info from the wp-config file) to a temporary file on the host. It will then download the file via SFTP into the local working directory. The uploads folders will be tarred to a temporary location on the host and then downloaded via SFTP to the local working directory.

If you run the this command: 

    wp-migrate retrieve myhost1

Then these files will appear in your current working directory.

* `myhost1.bkp.sql`
* `myhost1.bkp.tar`

### Transform

    wp-migrate transform <origin-host> <destination-host>

This command will look for the files that were created by `wp-migrate retrieve` and will attempt to load the SQL dump into the temporary database for processing. It will then go through the tables and look in common places for URL's and replace the URL's based on the config specified. Finally the command dumps the data from the database and creates files of both the data and the tar file. These files are then ready for `wp-migrate deliver` to be used.

For example, if you run the this command: 

	wp-migrate transform myhost1 myhost2

With these files in your current working directory:

* `myhost1.bkp.sql`
* `myhost1.bkp.tar`

Then these additional files will be created in your current working directory:

* `myhost2.transformed.sql`
* `myhost2.transformed.tar`

### Deliver

    wp-migrate deliver <destination-host>

This command will take the files created by `wp-migrate transform` and attempt to move them into the appropriate location in `<destination-host>` based on the config. The database sql dump will be transferred via SFTP (if necessary) and then loaded into the destination database replacing the contents on the destination database. Databse connections are determined by the `wp-config.php` file on the destination. The tar file will be transferred via SFTP (if necessary) ungzipped and untarred in location in the destination, replacing previous contents of the upload directory.

For example, if you run the this command: 

	wp-migrate deliver myhost2

With these files in your current working directory:

* `myhost2.transformed.sql`
* `myhost2.transformed.tar`
* `myhost2.bkp.sql`
* `myhost2.bkp.tar`

Then the transformed files will be installed in the destination installation.


### Auto

	wp-migrate auto <origin-host> <destination-host>

This command calls 'retrieve', 'transform', and then 'deliver' commands respectively.

After completing, the command will prompt the user if they would like to clean up the current working directory and remove the files for the host named mentioned.


Future Enhancements / Known Issues
------

* If the `wp-path` is not sufficiently unique then ordinary URLs or 
  other text in content and config might match the find-and-replace and therefore 
  unexpected changes in content occur. 
* We are currently use [phpseclib](https://github.com/phpseclib/phpseclib) version `0.3.5` since the latest version, `0.3.7` has an [issue uploading large files](https://github.com/phpseclib/phpseclib/issues/455) in our testing. If this is fixed, we will point to the latest version using composer.

License
------

wp-migrate is released under the [MIT License](http://opensource.org/licenses/MIT).

Copyright (C) 2014

