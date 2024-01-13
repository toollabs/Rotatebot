PHP bot to rotate files on Wikimedia Commons.

## Access data
The bot reads its user name and password from a file named `accessdata.php`, put next to `rotbot.php`. Create it with content similar to:
```
<?php
$botusername = "BotName";
$botkey = "BotPasswordName@BotPassword";
```

You can optionally specify an API URL as well (useful for testing with a local wiki or Beta Commons, not necessary for production use):
```
$botapi = "https://commons.wikimedia.beta.wmflabs.org/w/api.php";
