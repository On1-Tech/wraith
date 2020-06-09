<?php

// Class for Wraith database management

class DBManager {


    /*

    PROPERTIES

    */

    // The location of the database file. This can be edited, for example to
    // force the API to share a database with other APIs (not recommended) or
    // when changing the file structure. The path can be relative or full but
    // when relative, the path will be relative to the api.php file, not this
    // file.
    private $dbLocation = "./storage/wraithdb";

    // Database object (not exposed to functions outside of the class to
    // prevent low-level access and limit database access to what is defined
    // in this class)
    private $db;

    // Array of database commands which, when executed, initialise the
    // database from a blank state to something useable by the API.
    // These commands are defined in the object constructor below.
    private $dbInitCommands = [];

    /*

    METHODS

    */

    // OBJECT CONSTRUCTOR AND DESTRUCTOR

    // On object creation
    function __construct() {

        // Create the database connection
        // This can be edited to use a different database such as MySQL
        // but most of the SQL statements below will need to be edited
        // to work with the new database.
        $this->db = new PDO("sqlite:" . $this->dbLocation);

        // Start a transaction (prevent modification to the database by other
        // scripts running at the same time). If a transaction is currently in
        // progress, this will error so a try/catch and a loop is needed.
        while (true) {

            try {

                $this->db->beginTransaction();
                break;

            } catch (PDOException $e) {}

        }

        // Set database error handling policy
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Define the SQL commands used to initialise the database
        $this->dbInitCommands = [

            // SETTINGS Table
            "CREATE TABLE IF NOT EXISTS `WraithAPI_Settings` (
                `key` TEXT NOT NULL UNIQUE PRIMARY KEY,
                `value` TEXT
            );",
            // EVENTS Table
            "CREATE TABLE IF NOT EXISTS `WraithAPI_EventHistory` (
                `eventID` TEXT NOT NULL UNIQUE PRIMARY KEY,
                `eventType` TEXT,
                `eventTime` TEXT,
                `eventProperties` TEXT
            );",
            // CONNECTED WRAITHS Table
            "CREATE TABLE IF NOT EXISTS `WraithAPI_ActiveWraiths` (
                `assignedID` TEXT NOT NULL UNIQUE PRIMARY KEY,
                `hostProperties` TEXT,
                `wraithProperties` TEXT,
                `lastHeartbeatTime` TEXT,
                `issuedCommands` TEXT
            );",
            // COMMAND QUEUE Table
            "CREATE TABLE IF NOT EXISTS `WraithAPI_CommandsIssued` (
                `commandID` TEXT NOT NULL UNIQUE PRIMARY KEY,
                `commandName` TEXT,
                `commandParams` TEXT,
                `commandTargets` TEXT,
                `commandResponses` TEXT,
                `timeIssued` TEXT
            );",
            // USERS Table
            "CREATE TABLE IF NOT EXISTS `WraithAPI_Users` (
                `userName` TEXT NOT NULL UNIQUE PRIMARY KEY,
                `userPassword` TEXT,
                `userPrivileges` TEXT,
                `userFailedLogins` INTEGER,
                `userFailedLoginsTimeoutStart` TEXT
            );",
            // SESSIONS Table
            "CREATE TABLE IF NOT EXISTS `WraithAPI_Sessions` (
                `assignedID` TEXT NOT NULL UNIQUE PRIMARY KEY,
                `username` TEXT,
                `sessionToken` TEXT,
                `lastHeartbeatTime` TEXT
            );",
            // SETTINGS entries
            "INSERT INTO `WraithAPI_Settings` VALUES (
                'wraithMarkOfflineDelay',
                '16'
            );",
            "INSERT INTO `WraithAPI_Settings` VALUES (
                'wraithInitialCryptKey',
                'QWERTYUIOPASDFGHJKLZXCVBNM'
            );",
            "INSERT INTO `WraithAPI_Settings` VALUES (
                'wraithSwitchCryptKey',
                'QWERTYUIOPASDFGHJKLZXCVBNM'
            );",
            "INSERT INTO `WraithAPI_Settings` VALUES (
                'APIFingerprint',
                'ABCDEFGHIJKLMNOP'
            );",
            "INSERT INTO `WraithAPI_Settings` VALUES (
                'wraithDefaultCommands',
                '" . json_encode([]) . "'
            );",
            "INSERT INTO `WraithAPI_Settings` VALUES (
                'APIPrefix',
                'W_'
            );",
            "INSERT INTO `WraithAPI_Settings` VALUES (
                'requestIPBlacklist',
                '" . json_encode([]) . "'
            );",
            "INSERT INTO `WraithAPI_Settings` VALUES (
                'managementSessionExpiryDelay',
                '12'
            );",
            "INSERT INTO `WraithAPI_Settings` VALUES (
                'managementFirstLayerEncryptionKey',
                '" . bin2hex(random_bytes(25)) . "'
            );",
            "INSERT INTO `WraithAPI_Settings` VALUES (
                'managementIPWhitelist',
                '[]'
            );",
            "INSERT INTO `WraithAPI_Settings` VALUES (
                'managementBruteForceMaxAttempts',
                '3'
            );",
            "INSERT INTO `WraithAPI_Settings` VALUES (
                'managementBruteForceTimeoutSeconds',
                '300'
            );",
            // Mark the database as initialised
            "CREATE TABLE IF NOT EXISTS `DB_INIT_INDICATOR` (
                `DB_INIT_INDICATOR` INTEGER
            );"

        ];

        // Check if the database was initialised
        if (!($this->isDatabasePostInit())) {

            $this->initDB();

        }

        // Check if a user account exists (LIMIT 1 for efficiency)
        if (sizeof($this->dbGetUsers([], 1, 0)) < 1) {

            // A user should be added to allow managing the API
            $this->dbAddUser([
                "userName" => "SuperAdmin",
                "userPassword" => "SuperAdminPass",
                "userPrivilegeLevel" => 2
            ]);

        }

    }

    // On object destruction
    function __destruct() {

        // Commit database changes (write changes made during the runtime of the
        // script to the database and allow other scripts to access the database)
        $this->db->commit();

        // Close the database connection
        $this->db = NULL;

    }

    // HELPERS (internal)

    // Execute SQL on the database with optional parameters using secure
    // prepared statements
    private function SQLExec($SQL, $params = []) {

        $statement = $this->db->prepare($SQL);

        $statement->execute($params);

        // Return the statement so further actions can be performed on it like
        // fetchAll().
        return $statement;

    }

    // Convert an array into a SQL WHERE clause for use as a filter
    // Adapted from https://stackoverflow.com/a/62181134/8623347
    private function generateFilter($filter, $columnNameWhitelist, $limit = -1, $offset = -1) {

        $conditions = [];
        $parameters = [];

        foreach ($filter as $key => $values) {

            // Ensure that the column names are whitelisted to prevent SQL
            // injection
            if (array_search($key, $columnNameWhitelist, true) === false) {

                throw new InvalidArgumentException("invalid field name in filter");

            }

            // Generate the SQL for each condition and add the values to the list
            // of parameters
            $conditions[] = "`$key` in (".str_repeat('?,', count($values) - 1) . '?'.")";
            $parameters = array_merge($parameters, $values);

        }

        // Generate the SQL (no SQL needs to be generated if no conditions
        // were given)
        $sql = "";
        if ($conditions) {

            $sql .= " WHERE " . implode(" AND ", $conditions);

        }

        // Add the LIMIT and OFFSET for pagination

        if ((int)$limit >= 0) {

            $sql .= " LIMIT " . (int)$limit;

        }

        if ((int)$offset >= 1) {

            $sql .= " OFFSET " . (int)$offset;

        }

        // The filter should now be translated into valid SQL and parameters
        // so it can be returned
        return [$sql, $parameters];

    }

    // DATABASE MANAGEMENT (internal)

    // Check if the database has been initialised
    private function isDatabasePostInit() {

        // Check if the DB_INIT_INDICATOR table exists
        $statement = $this->SQLExec("SELECT name FROM sqlite_master WHERE type='table' AND name='DB_INIT_INDICATOR' LIMIT 1;");

        // Convert the result into a boolean
        // The result will be an array of all tables named "DB_INIT_INDICATOR"
        // If the array is of length 0 (no such table), the boolean will be false.
        // All other cases result in true (the only other possible case here is 1).
        $dbIsPostInit = (bool)sizeof($statement->fetchAll());

        if ($dbIsPostInit) {

            // DB_INIT_INDICATOR exists
            return true;

        } else {

            // DB_INIT_INDICATOR does not exist
            return false;

        }

    }

    // Initialise the database
    private function initDB() {

        // Execute each command in dbInitCommands to initialise the database
        foreach ($this->dbInitCommands as $command) {

            try {

                $this->SQLExec($command);

            } catch (PDOException $e) {

                return false;

            }

        }

        // If false was not yet returned, everything was successful
        return true;

    }

    // Delete all Wraith API tables from the database
    // (init will not be called automatically)
    private function clearDB() {

        // The following will generate an array of SQL commands which will
        // delete every table in the database
        $statement = $this->SQLExec("SELECT 'DROP TABLE ' || name ||';' FROM sqlite_master WHERE type = 'table';");

        // Get the SQL commands
        $commands = $statement->fetchAll();

        foreach ($commands as $command) {

            $this->SQLExec($command[0]);

        }

    }

    // ACTIVE WRAITH TABLE MANAGEMENT (public)

    // Add a Wraith to the database
    function dbAddWraith($data) {

        $SQL = "INSERT INTO `WraithAPI_ActiveWraiths` (
                `assignedID`,
                `hostProperties`,
                `wraithProperties`,
                `lastHeartbeatTime`,
                `issuedCommands`
            ) VALUES (
                ?,
                ?,
                ?,
                ?,
                ?
            )";

        $params = [
            $data["assignedID"],
            $data["hostProperties"],
            $data["wraithProperties"],
            $data["lastHeartbeatTime"],
            $data["issuedCommands"],
        ];

        $this->SQLExec($SQL, $params);

    }

    // Remove Wraith(s)
    function dbRemoveWraiths($filter = [], $limit = -1, $offset = -1) {

        $validFilterColumnNames = [
            "assignedID",
            "hostProperties",
            "wraithProperties",
            "lastHeartbeatTime",
            "issuedCommands"
        ];

        $SQL = "DELETE FROM `WraithAPI_ActiveWraiths`";

        $params = [];

        // Apply the filters
        $filterSQL = $this->generateFilter($filter, $validFilterColumnNames, $limit, $offset);
        $SQL .= $filterSQL[0];
        $params = array_merge($params, $filterSQL[1]);

        $statement = $this->SQLExec($SQL, $params);

    }

    // Get a list of Wraiths and their properties
    function dbGetWraiths($filter = [], $limit = -1, $offset = -1) {

        $validFilterColumnNames = [
            "assignedID",
            "hostProperties",
            "wraithProperties",
            "lastHeartbeatTime",
            "issuedCommands"
        ];

        $SQL = "SELECT * FROM WraithAPI_ActiveWraiths";

        $params = [];

        // Apply the filters
        $filterSQL = $this->generateFilter($filter, $validFilterColumnNames, $limit, $offset);
        $SQL .= $filterSQL[0];
        $params = array_merge($params, $filterSQL[1]);

        $statement = $this->SQLExec($SQL, $params);

        // Get a list of wraiths from the database
        $wraithsDB = $statement->fetchAll();

        $wraiths = [];

        foreach ($wraithsDB as $wraith) {

            // Move the assigned ID to a separate variable
            $wraithID = $wraith["assignedID"];
            unset($wraith["assignedID"]);

            $wraiths[$wraithID] = $wraith;

        }

        return $wraiths;

    }

    // Update the Wraith last heartbeat time
    function dbUpdateWraithLastHeartbeat($assignedID) {

        // Update the last heartbeat time to the current time
        $SQL = "UPDATE WraithAPI_ActiveWraiths SET `lastHeartbeatTime` = ? WHERE `assignedID` = ?;";

        $params = [
            time(),
            $assignedID
        ];

        $this->SQLExec($SQL, $params);

    }

    // Check which Wraiths have not sent a heartbeat in the mark dead time and remove
    // them from the database
    function dbExpireWraiths() {

        // Remove all Wraith entries where the last heartbeat time is older than
        // the $SETTINGS["wraithMarkOfflineDelay"]
        $SQL = "DELETE FROM `WraithAPI_ActiveWraiths` WHERE `lastHeartbeatTime` < ?";

        $params = [
            // Get the unix timestamp for $SETTINGS["wraithMarkOfflineDelay"] seconds ago
            $earliestValidHeartbeat = time()-$this->dbGetSettings(["key" => "wraithMarkOfflineDelay"])["wraithMarkOfflineDelay"]
        ];

        $this->SQLExec($SQL, $params);

    }

    // ISSUED COMMAND TABLE MANAGEMENT (public)

    // Issue a command to Wraith(s)
    function dbAddCommand($data) {

        // TODO

    }

    // Delete command(s) from the command table
    function dbRemoveCommands($filter = [], $limit = -1, $offset = -1) {

        // TODO
        $statement = $this->db->prepare("DELETE FROM `WraithAPI_CommandsIssued` WHERE assignedID == :IDToDelete");

        // Remove each ID
        foreach ($ids as $id) {

            $statement->bindParam(":IDToDelete", $id);
            $statement->execute();

        }

    }

    // Get command(s)
    function dbGetCommands($filter = [], $limit = -1, $offset = -1) {

        // TODO

    }

    // SETTINGS TABLE MANAGEMENT (public)

    // Edit an API setting
    function dbSetSetting($name, $value) {

        // Update setting value
        $SQL = "UPDATE WraithAPI_Settings SET `value` = ? WHERE `key` = ?;";

        $params = [
            $value,
            $name
        ];

        $this->SQLExec($SQL, $params);

    }

    // Refresh the settings property of the DBManager
    function dbGetSettings($filter = [], $limit = -1, $offset = -1) {

        $validFilterColumnNames = [
            "key",
            "value"
        ];

        $SQL = "SELECT * FROM WraithAPI_Settings";

        $params = [];

        // Apply the filters
        $filterSQL = $this->generateFilter($filter, $validFilterColumnNames, $limit, $offset);
        $SQL .= $filterSQL[0];
        $params = array_merge($params, $filterSQL[1]);

        $statement = $this->SQLExec($SQL, $params);

        $result = $statement->fetchAll();

        // Format the results
        $settings = [];
        foreach ($result as $tableRow) {

            $settings[$tableRow[0]] = $tableRow[1];

        }

        return $settings;

    }

    // USERS TABLE MANAGEMENT (public)

    // Create a new user
    function dbAddUser($data) {

        $this->SQLExec("INSERT INTO `WraithAPI_Users` (
                `userName`,
                `userPassword`,
                `userPrivileges`,
                `userFailedLogins`,
                `userFailedLoginsTimeoutStart`
            ) VALUES (
                ?,
                ?,
                ?,
                '0',
                '0'
            );",
            [
                $data["userName"],
                password_hash($data["userPassword"], PASSWORD_BCRYPT),
                $data["userPrivilegeLevel"]
            ]
        );

    }

    // Delete a user
    function dbRemoveUsers($filter = [], $limit = -1, $offset = -1) {

        $validFilterColumnNames = [
            "userName",
            "userPassword",
            "userPrivileges",
            "userFailedLoginsTimeoutStart"
        ];

        $SQL = "DELETE FROM `WraithAPI_Users`";

        $params = [];

        // Apply the filters
        $filterSQL = $this->generateFilter($filter, $validFilterColumnNames, $limit, $offset);
        $SQL .= $filterSQL[0];
        $params = array_merge($params, $filterSQL[1]);

        $statement = $this->SQLExec($SQL, $params);

    }

    // Get a list of users and their properties
    function dbGetUsers($filter = [], $limit = -1, $offset = -1) {

        $validFilterColumnNames = [
            "userName",
            "userPassword",
            "userPrivileges",
            "userFailedLogins",
            "userFailedLoginsTimeoutStart"
        ];

        $SQL = "SELECT * FROM WraithAPI_Users";

        $params = [];

        // Apply the filters
        $filterSQL = $this->generateFilter($filter, $validFilterColumnNames, $limit, $offset);
        $SQL .= $filterSQL[0];
        $params = array_merge($params, $filterSQL[1]);

        $statement = $this->SQLExec($SQL, $params);

        // Get a list of users from the database
        $usersDB = $statement->fetchAll();

        $users = [];

        foreach ($usersDB as $user) {

            // Move the userName to a separate variable
            $userName = $user["userName"];
            unset($user["userName"]);

            $users[$userName] = $user;

        }

        return $users;

    }

    // Change username
    function dbChangeUserName($currentUsername, $newUsername) {

        // Update userName value
        $SQL = "UPDATE WraithAPI_Users SET `userName` = ? WHERE `userName` = ?;";

        $params = [
            $newUsername,
            $currentUsername
        ];

        $this->SQLExec($SQL, $params);

    }

    // Verify that a user password is correct
    function dbVerifyUserPass($username, $password) {

        $user = $this->dbGetUsers([
            "userName" => [$username]
        ])[$username];

        return password_verify($password, $user["userPassword"]);

    }

    // Change user password
    function dbChangeUserPass($username, $newPassword) {

        // Update userPassword value
        $SQL = "UPDATE WraithAPI_Users SET `userPassword` = ? WHERE `userName` = ?;";

        $params = [
            password_hash($newPassword, PASSWORD_BCRYPT),
            $username
        ];

        $this->SQLExec($SQL, $params);


    }

    // Change user privilege level (0=User, 1=Admin, 2=SuperAdmin)
    function dbChangeUserPrivilege($username, $newPrivilegeLevel) {

        // Update userPassword value
        $SQL = "UPDATE WraithAPI_Users SET `userPrivileges` = ? WHERE `userName` = ?;";

        $params = [
            $newPrivilegeLevel,
            $username
        ];

        $this->SQLExec($SQL, $params);

    }

    // SESSIONS TABLE MANAGEMENT (public)

    // Create a session for a user
    function dbAddSession($data) {

        $statement = $this->db->prepare("INSERT INTO `WraithAPI_Sessions` (
            `sessionID`,
            `username`,
            `sessionToken`,
            `lastSessionHeartbeat`
        ) VALUES (
            :sessionID,
            :username,
            :sessionToken,
            :lastSessionHeartbeat
        )");

        // Create session variables
        $sessionID = uniqid();
        $sessionToken = bin2hex(random_bytes(25));
        $lastSessionHeartbeat = time();

        $statement->bindParam(":username", $username);
        $statement->bindParam(":sessionID", $sessionID);
        $statement->bindParam(":sessionToken", $sessionToken);
        $statement->bindParam(":lastSessionHeartbeat", $lastSessionHeartbeat);

        $statement->execute();

        return $sessionID;

    }

    // Delete a session
    function dbRemoveSessions($filter = [], $limit = -1, $offset = -1) {

        // Remove the session with the specified ID
        $statement = $this->db->prepare("DELETE FROM `WraithAPI_Sessions`
            WHERE `sessionID` = :sessionID");

        $statement->bindParam(":sessionID", $sessionID);

        $statement->execute();

    }

    // Get a list of all sessions
    function dbGetSessions($filter = [], $limit = -1, $offset = -1) {

        // Get a list of sessions from the database
        $sessionsDB = $this->db->query("SELECT * FROM WraithAPI_Sessions")->fetchAll();

        $sessions = [];

        foreach ($sessionsDB as $session) {

            // Move the session ID to a separate variable
            $sessionID = $session["sessionID"];
            unset($session["sessionID"]);

            $sessions[$sessionID] = $session;

        }

        return $sessions;

    }

    // Update the session last heartbeat time
    function dbUpdateSessionLastHeartbeat($assignedID) {

        // Update the last heartbeat time to the current time
        $SQL = "UPDATE WraithAPI_Sessions SET `lastHeartbeatTime` = ? WHERE `assignedID` = ?;";

        $params = [
            time(),
            $assignedID
        ];

        $this->SQLExec($SQL, $params);

    }

    // Delete sessions which have not had a heartbeat recently
    function dbExpireSessions() {

        // Remove all sessions where the last heartbeat time is older than
        // the $SETTINGS["managementSessionExpiryDelay"]
        $statement = $this->db->prepare("DELETE FROM `WraithAPI_Sessions`
            WHERE `lastSessionHeartbeat` < :earliestValidHeartbeat");

        // Get the unix timestamp for $SETTINGS["managementSessionExpiryDelay"] seconds ago
        $earliestValidHeartbeat = time()-$SETTINGS["managementSessionExpiryDelay"];
        $statement->bindParam(":earliestValidHeartbeat", $earliestValidHeartbeat);

        $statement->execute();

    }

    // STATS TABLE MANAGEMENT (public)

    // Update a statistic
    function dbSetStat($name, $value) {

        // Update a stat
        $statement = $this->db->prepare("UPDATE WraithAPI_Stats
            SET `value` = :value WHERE `key` = :stat;");

        $statement->bindParam(":stat", $stat);
        $statement->bindParam(":value", $value);

        $statement->execute();

    }

    // Update a statistic
    function dbGetStats($filter = [], $limit = -1, $offset = -1) {

        // Get a list of statistics from the database
        $statsDB = $this->db->query("SELECT * FROM WraithAPI_Stats")->fetchAll();

        $stats = [];

        foreach ($statsDB as $stat) {

            $key = $stat["key"];

            $stats[$key] = $stat["value"];

        }

        return $stats;

    }

    // MISC

    // Re-generate the first-layer encryption key for management sessions
    function dbRegenMgmtCryptKeyIfNoSessions() {

        // If there are no active sessions
        $allSessions = dbGetSessions();
        if (sizeof($allSessions) == 0) {

            // Update the first layer encryption key
            dbSetSetting("managementFirstLayerEncryptionKey", bin2hex(random_bytes(25)));

        }

    }

}

// Create an instance of the database manager
$dbm = new DBManager();
