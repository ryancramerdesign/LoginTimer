# Login Timer for ProcessWire

**Prevents timing attacks by enabling normalization of login 
times so that a failed login is no faster than a successful login.**

This prevents timing attacks from discovering any information 
about good vs. bad user names or passwords based on the time 
taken to process the login request. It does this by remembering 
how long successful logins take and applying that same amount of 
time to failed logins.

[Details and documentation](https://processwire.com/blog/posts/timing-attacks-and-how-to-prevent-them)

### Installation 

1. Copy all files from this module to `/site/modules/LoginTimer/`
2. Go to Modules > Refresh in your admin.
3. Install the Site > Login > Login Timer module. 
4. Logout and log back in. 

The last step above will prime the login timer so that it can 
establish an appropriate login time for your system. This 
time is recalculated up to 24 times per day.

Once installed, this module will automatically apply to all logins
from ProcessWire’s `$session` API variable. For instance, logins
from the ProcessWire login form, and the LoginRegisterPro module
are covered by this module. 