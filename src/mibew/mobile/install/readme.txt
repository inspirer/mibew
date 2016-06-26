Instructions to install the MibewMob Server Extension which provides the necessary infrastructure for the MibewMob app to communicate with your Mibew messenger installation:

Fresh Install
=============
1 - Download the MibewMob server extension (If you are reading this then you have have already done so)
2 - Extract the contents of the zip file
3 - Copy the "mobile" directory to the root of the mibew directory on your server
4 - In your browser navigate to <mibew url>/mobile/install
5 - Follow the directions to install the required database tables
6 - When you are done, delete the install folder from your server for security reasons


Upgrading
=========
Before upgrading, make a copy of your database so that you can revert if something goes wrong!
1 - Download the MibewMob server extension (If you are reading this then you have have already done so)
2 - Extract the contents of the zip file
3 - On your server, rename the "mobile" directory under <mibew url> to "mobile.old"
4 - Copy the "mobile" directory to the root of the mibew directory on your server
5 - In your browser navigate to <mibew url>/mobile/install
6 - Follow the directions to upgrade the required database tables
7 - Test with a compatible version of the MibewMob app that everything is working alright.
8 - When you are done, delete the install folder from your server for security reasons
9 - Delete mobile.old too.
