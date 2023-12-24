PHP bot to rotate files on Wikimedia Commons.

## Access data
The bot reads its user name and password from a file named `accessdata.php`, put next to `rotbot.php`. Create it with content similar to:
```
<?php
$botusername = "BotName";
$botkey = "BotPasswordName@BotPassword";
```
