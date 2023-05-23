<?php

class Am_DemoBuilder {

    protected $id = null;

    //http://www.world-english.org/boys_names_list.htm
    protected $name_f = array(
            'Aaron', 'Abbott', 'Abel', 'Abner', 'Abraham', 'Adam', 'Addison', 'Adler',
            'Adley', 'Adrian', 'Aedan', 'Aiken', 'Alan', 'Alastair', 'Albern', 'Albert',
            'Albion', 'Alden', 'Aldis', 'Aldrich', 'Alexander', 'Alfie', 'Alfred',
            'Algernon', 'Alston', 'Alton', 'Alvin', 'Ambrose', 'Amery', 'Amos',
            'Andrew', 'Angus', 'Ansel', 'Anthony', 'Archer', 'Archibald', 'Arlen',
            'Arnold', 'Arthur', 'Arvel', 'Atwater', 'Atwood', 'Aubrey', 'Austin',
            'Avery', 'Axel', 'Baird', 'Baldwin', 'Barclay', 'Barnaby', 'Baron',
            'Barrett', 'Barry', 'Bartholomew', 'Basil', 'Benedict', 'Benjamin',
            'Benton', 'Bernard', 'Bert', 'Bevis', 'Blaine', 'Blair', 'Blake', 'Bond',
            'Boris', 'Bowen', 'Braden', 'Bradley', 'Brandan', 'Brent', 'Bret', 'Brian',
            'Brice', 'Brigham', 'Brock', 'Broderick', 'Brooke', 'Bruce', 'Bruno',
            'Bryant', 'Buck', 'Bud', 'Burgess', 'Burton', 'Byron', 'Cadman', 'Calvert',
            'Caldwell', 'Caleb', 'Calvin', 'Carrick', 'Carl', 'Carlton', 'Carney',
            'Carroll', 'Carter', 'Carver', 'Cary', 'Casey', 'Casper', 'Cecil', 'Cedric',
            'Chad', 'Chalmers', 'Chandler', 'Channing', 'Chapman', 'Charles', 'Chatwin',
            'Chester', 'Christian', 'Christopher', 'Clarence', 'Claude', 'Clayton',
            'Clifford', 'Clive', 'Clyde', 'Coleman', 'Colin', 'Collier', 'Conan',
            'Connell', 'Connor', 'Conrad', 'Conroy', 'Conway', 'Corwin', 'Crispin',
            'Crosby', 'Culbert', 'Culver', 'Curt', 'Curtis', 'Cuthbert', 'Craig',
            'Cyril', 'Dale', 'Dalton', 'Damon', 'Daniel', 'Darcy', 'Darian', 'Darell',
            'David', 'Davin', 'Dean', 'Declan', 'Delmar', 'Denley', 'Dennis', 'Derek',
            'Dermot', 'Derwin', 'Des', 'Dexter', 'Dillon', 'Dion', 'Dirk', 'Dixon',
            'Dominic', 'Donald', 'Dorian', 'Douglas', 'Doyle', 'Drake', 'Drew',
            'Driscoll', 'Dudley', 'Duncan', 'Durwin', 'Dwayne', 'Dwight', 'Dylan',
            'Earl', 'Eaton', 'Ebenezer', 'Edan', 'Edgar', 'Edric', 'Edmond', 'Edward',
            'Edwin', 'Efrain', 'Egan', 'Egbert', 'Egerton', 'Egil', 'Elbert', 'Eldon',
            'Eldwin', 'Eli', 'Elias', 'Eliot', 'Ellery', 'Elmer', 'Elroy', 'Elton',
            'Elvis', 'Emerson', 'Emmanuel', 'Emmett', 'Emrick', 'Enoch', 'Eric',
            'Ernest', 'Errol', 'Erskine', 'Erwin', 'Esmond', 'Ethan', 'Ethen', 'Eugene',
            'Evan', 'Everett', 'Ezra', 'Fabian', 'Fairfax', 'Falkner', 'Farley',
            'Farrell', 'Felix', 'Fenton', 'Ferdinand', 'Fergal', 'Fergus', 'Ferris',
            'Finbar', 'Fitzgerald', 'Fleming', 'Fletcher', 'Floyd', 'Forbes', 'Forrest',
            'Foster', 'Fox', 'Francis', 'Frank', 'Frasier', 'Frederick', 'Freeman',
            'Gabriel', 'Gale', 'Galvin', 'Gardner', 'Garret', 'Garrick', 'Garth',
            'Gavin', 'George', 'Gerald', 'Gideon', 'Gifford', 'Gilbert', 'Giles',
            'Gilroy', 'Glenn', 'Goddard', 'Godfrey', 'Godwin', 'Graham', 'Grant',
            'Grayson', 'Gregory', 'Gresham', 'Griswald', 'Grover', 'Guy', 'Hadden',
            'Hadley', 'Hadwin', 'Hal', 'Halbert', 'Halden', 'Hale', 'Hall', 'Halsey',
            'Hamlin', 'Hanley', 'Hardy', 'Harlan', 'Harley', 'Harold', 'Harris',
            'Hartley', 'Heath', 'Hector', 'Henry', 'Herbert', 'Herman', 'Homer',
            'Horace', 'Howard', 'Hubert', 'Hugh', 'Humphrey', 'Hunter', 'Ian', 'Igor',
            'Irvin', 'Isaac', 'Isaiah', 'Ivan', 'Iver', 'Ives', 'Jack', 'Jacob',
            'James', 'Jarvis', 'Jason', 'Jasper', 'Jed', 'Jeffrey', 'Jeremiah',
            'Jerome', 'Jesse', 'John', 'Jonathan', 'Joseph', 'Joshua', 'Justin', 'Kane',
            'Keene', 'Keegan', 'Keaton', 'Keith', 'Kelsey', 'Kelvin', 'Kendall',
            'Kendrick', 'Kenneth', 'Kent', 'Kenway', 'Kenyon', 'Kerry', 'Kerwin',
            'Kevin', 'Kiefer', 'Kilby', 'Kilian', 'Kim', 'Kimball', 'Kingsley', 'Kirby',
            'Kirk', 'Kit', 'Kody', 'Konrad', 'Kurt', 'Kyle', 'Lambert', 'Lamont',
            'Lancelot', 'Landon', 'Landry', 'Lane', 'Lars', 'Laurence', 'Lee', 'Leith',
            'Leonard', 'Leroy', 'Leslie', 'Lester', 'Lincoln', 'Lionel', 'Lloyd',
            'Logan', 'Lombard', 'Louis', 'Lowell', 'Lucas', 'Luther', 'Lyndon',
            'Maddox', 'Magnus', 'Malcolm', 'Melvin', 'Marcus', 'Mark', 'Marlon',
            'Martin', 'Marvin', 'Matthew', 'Maurice', 'Max', 'Medwin', 'Melville',
            'Merlin', 'Michael', 'Milburn', 'Miles', 'Monroe', 'Montague', 'Montgomery',
            'Morgan', 'Morris', 'Morton', 'Murray', 'Nathaniel', 'Neal', 'Neville',
            'Nicholas', 'Nigel', 'Noel', 'Norman', 'Norris', 'Olaf', 'Olin', 'Oliver',
            'Orson', 'Oscar', 'Oswald', 'Otis', 'Owen', 'Paul', 'Paxton', 'Percival',
            'Perry', 'Peter', 'Peyton', 'Philbert', 'Philip', 'Phineas', 'Pierce',
            'Quade', 'Quenby', 'Quillan', 'Quimby', 'Quentin', 'Quinby', 'Quincy',
            'Quinlan', 'Quinn', 'Ralph', 'Ramsey', 'Randolph', 'Raymond', 'Reginald',
            'Renfred', 'Rex', 'Rhett', 'Richard', 'Ridley', 'Riley', 'Robert',
            'Roderick', 'Rodney', 'Roger', 'Roland', 'Rolf', 'Ronald', 'Rory',
            'Ross', 'Roswell', 'Roy', 'Royce', 'Rufus', 'Rupert', 'Russell',
            'Ryan'
    );

    protected $name_l = array (
            'Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Miller', 'Davis',
            'Garcia', 'Rodriguez', 'Wilson', 'Martinez', 'Anderson', 'Taylor',
            'Thomas', 'Hernandez', 'Moore', 'Martin', 'Jackson', 'Thompson', 'White',
            'Lopez', 'Lee', 'Gonzalez', 'Harris', 'Clark', 'Lewis', 'Robinson',
            'Walker', 'Perez', 'Hall', 'Young', 'Allen', 'Sanchez', 'Wright', 'King',
            'Scott', 'Green', 'Baker', 'Adams', 'Nelson', 'Hill', 'Ramirez', 'Campbell',
            'Mitchell', 'Roberts', 'Carter', 'Phillips', 'Evans', 'Turner', 'Torres',
            'Parker', 'Collins', 'Edwards', 'Stewart', 'Flores', 'Morris', 'Nguyen',
            'Murphy', 'Rivera', 'Cook', 'Rogers', 'Morgan', 'Peterson', 'Cooper',
            'Reed', 'Bailey', 'Bell', 'Gomez', 'Kelly', 'Howard', 'Ward', 'Cox', 'Diaz',
            'Richardson', 'Wood', 'Watson', 'Brooks', 'Bennett', 'Gray', 'James',
            'Reyes', 'Cruz', 'Hughes', 'Price', 'Myers', 'Long', 'Foster', 'Sanders',
            'Ross', 'Morales', 'Powell', 'Sullivan', 'Russell', 'Ortiz', 'Jenkins',
            'Gutirrez', 'Perry', 'Butler', 'Barnes', 'Fisher'
    );

    protected $countries = array ('US');


//http://en.wikipedia.org/wiki/List_of_cities,_towns,_and_villages_in_the_United_States
    protected $cities = array(
            'NY' => array (
                            'Albany', 'Amsterdam', 'Auburn', 'Batavia', 'Beacon', 'Binghamton',
                            'Buffalo', 'Canandaigua', 'Cohoes', 'Corning', 'Cortland', 'Dunkirk',
                            'Elmira', 'Fulton', 'Geneva', 'Glen Cove', 'Glens Falls', 'Gloversville',
                            'Hornell', 'Hudson', 'Ithaca', 'Jamestown', 'Johnstown', 'Kingston',
                            'Lackawanna', 'Little Falls', 'Lockport', 'Long Beach', 'Mechanicville',
                            'Middletown', 'Mount Vernon', 'New Rochelle', 'New York City', 'Newburgh',
                            'Niagara Falls', 'North Tonawanda', 'Norwich', 'Ogdensburg', 'Olean',
                            'Oneida', 'Oneonta', 'Oswego', 'Peekskill', 'Plattsburgh', 'Port Jervis',
                            'Poughkeepsie', 'Rensselaer', 'Rochester', 'Rome', 'Rye', 'Salamanca',
                            'Saratoga Springs', 'Schenectady', 'Sherrill', 'Syracuse', 'Tonawanda',
                            'Troy', 'Utica', 'Watertown', 'Watervliet', 'White Plains', 'Yonkers',
            ),
            'AL' =>  array (
                            'Abbeville', 'Adamsville', 'Addison', 'Akron', 'Alabaster', 'Albertville',
                            'Alexander City', 'Aliceville', 'Allgood', 'Altoona', 'Andalusia',
                            'Anderson', 'Anniston', 'Arab', 'Ardmore', 'Argo', 'Ariton', 'Arley',
                            'Ashford', 'Ashland', 'Ashville', 'Athens', 'Atmore', 'Attalla', 'Auburn',
                            'Autaugaville', 'Avon', 'Babbie', 'Baileyton', 'Banks', 'Bay Minette',
                            'Bayou La Batre', 'Bear Creek', 'Beatrice', 'Beaverton', 'Belk', 'Benton',
                            'Berry', 'Bessemer', 'Billingsley', 'Birmingham', 'Black', 'Blountsville',
                            'Blue Mountain', 'Blue Springs', 'Boaz', 'Boligee', 'Bon Air',
                            'Branchville', 'Brantley', 'Brent', 'Brewton', 'Bridgeport',
                            'Brighton', 'Brilliant', 'Brookside', 'Brookwood', 'Brundidge', 'Butler',
                            'Calera', 'Camden', 'Camp Hill', 'Carbon Hill', 'Cardiff', 'Carolina',
                            'Carrollton', 'Castleberry', 'Cedar Bluff', 'Centre', 'Centreville',
                            'Chatom', 'Chelsea', 'Cherokee', 'Chickasaw', 'Childersburg', 'Citronelle',
                            'Clanton', 'Clayhatchee', 'Clayton', 'Cleveland', 'Clio', 'Coaling',
                            'Coffee Springs', 'Coffeeville', 'Coker', 'Collinsville', 'Colony',
                            'Columbia', 'Columbiana', 'Coosada', 'Cordova', 'Cottonwood', 'County Line',
                            'Courtland', 'Cowarts', 'Creola', 'Crossville', 'Cuba', 'Cullman',
                            'Dadeville', 'Daleville', 'Daphne', 'Dauphin Island', 'Daviston',
                            'Dayton', 'Deatsville', 'Decatur', 'Demopolis', 'Detroit', 'Dodge City',
                            'Dora', 'Dothan', 'Double Springs', 'Douglas', 'Dozier', 'Dutton',
                            'East Brewton', 'Eclectic', 'Edwardsville', 'Elba', 'Elberta', 'Eldridge',
                            'Elkmont', 'Elmore', 'Emelle', 'Enterprise', 'Epes', 'Ethelsville',
                            'Eufaula', 'Eunola', 'Eutaw', 'Eva', 'Evergreen', 'Excel', 'Fairfield',
                            'Fairhope', 'Fairview', 'Falkville', 'Faunsdale', 'Fayette', 'Five Points',
                            'Flomaton', 'Florala', 'Florence', 'Foley', 'Forkland', 'Fort Deposit',
                            'Fort Payne', 'Franklin', 'Frisco City', 'Fruithurst', 'Fulton',
                            'Fultondale', 'Fyffe', 'Gadsden', 'Gainesville', 'Gantt', 'Gantts Quarry',
                            'Garden City', 'Gardendale', 'Gaylesville', 'Geiger', 'Geneva', 'Georgiana',
                            'Geraldine', 'Gilbertown', 'Glen Allen', 'Glencoe', 'Glenwood', 'Goldville',
                            'Good Hope', 'Goodwater', 'Gordo', 'Gordon', 'Gordonville', 'Goshen',
                            'Grant', 'Graysville', 'Greensboro', 'Greenville', 'Grimes', 'Grove Hill',
                            'Guin', 'Gulf Shores', 'Guntersville', 'Gurley', 'Gu-Win', 'Hackleburg',
                            'Haleburg', 'Haleyville', 'Hamilton', 'Hammondville', 'Hanceville',
                            'Harpersville', 'Hartford', 'Hartselle', 'Hayden', 'Hayneville',
                            'Headland', 'Heath', 'Heflin', 'Helena', 'Henagar', 'Highland Lake',
                            'Hillsboro', 'Hobson City', 'Hodges', 'Hokes Bluff', 'Holly Pond',
                            'Hollywood', 'Homewood', 'Hoover', 'Horn Hill', 'Hueytown', 'Huntsville',
                            'Hurtsboro', 'Hytop', 'Ider', 'Indian Springs Village', 'Irondale',
                            'Jackson', 'Jacksons\' Gap', 'Jacksonville', 'Jasper', 'Jemison', 'Kansas',
                            'Kennedy', 'Killen', 'Kimberly', 'Kinsey', 'Kinston', 'La Fayette',
                            'Lake View', 'Lakeview', 'Lanett', 'Langston', 'Leeds', 'Leesburg',
                            'Leighton', 'Lester', 'Level Plains', 'Lexington', 'Libertyville',
                            'Lincoln', 'Linden', 'Lineville', 'Lipscomb', 'Lisman', 'Littleville',
                            'Livingston', 'Loachapoka', 'Lockhart', 'Locust Fork', 'Louisville',
                            'Lowndesboro', 'Loxley', 'Luverne', 'Lynn', 'Macedonia', 'Madison',
                            'Madrid', 'Malvern', 'Maplesville', 'Margaret', 'Marion', 'Maytown',
                            'McIntosh', 'McKenzie', 'McMullen', 'Memphis', 'Mentone', 'Midfield',
                            'Midland City', 'Midway', 'Millbrook', 'Millport', 'Millry', 'Mobile',
                            'Monroeville', 'Montevallo', 'Montgomery', 'Moody', 'Mooresville', 'Morris',
                            'Mosses', 'Moulton', 'Moundville', 'Mount Vernon', 'Mountain Brook',
                            'Mountainboro', 'Mulga', 'Muscle Shoals', 'Myrtlewood', 'Napier Field',
                            'Natural Bridge', 'Nauvoo', 'Nectar', 'Needham', 'New Brockton', 'New Hope',
                            'New Site', 'Newbern', 'Newton', 'Newville', 'North Bibb', 'North Courtland',
                            'North Johns', 'Northport', 'Notasulga', 'Oak Grove', 'Oak Hill', 'Oakman',
                            'Odenville', 'Ohatchee', 'Oneonta', 'Onycha', 'Opelika', 'Opp',
                            'Orange Beach', 'Orrville', 'Owens Cross Roads', 'Oxford', 'Ozark',
                            'Paint Rock', 'Parrish', 'Pelham', 'Pell City', 'Pennington', 'Petrey',
                            'Phenix City', 'Phil Campbell', 'Pickensville', 'Piedmont', 'Pike Road',
                            'Pinckard', 'Pine Apple', 'Pine Hill', 'Pine Ridge', 'Pisgah',
                            'Pleasant Grove', 'Pleasant Groves', 'Pollard', 'Powell', 'Prattville',
                            'Priceville', 'Prichard', 'Providence', 'Ragland', 'Rainbow City',
                            'Rainsville', 'Ranburne', 'Red Bay', 'Red Level', 'Reece City', 'Reform',
                            'Rehobeth', 'Repton', 'Ridgeville', 'River Falls', 'Riverside', 'Riverview',
                            'Roanoke', 'Robertsdale', 'Rockford', 'Rogersville', 'Rosa', 'Russellville',
                            'Rutledge', 'Samson', 'Sand Rock', 'Sanford', 'Saraland', 'Sardis City',
                            'Satsuma', 'Scottsboro', 'Section', 'Selma', 'Sheffield', 'Shiloh',
                            'Shorter', 'Silas', 'Silverhill', 'Sipsey', 'Skyline', 'Slocomb', 'Snead',
                            'Somerville', 'South Vinemont', 'Southside', 'Spanish Fort',
                            'Springville', 'St. Florian', 'Steele', 'Stevenson', 'Sulligent', 'Sumiton',
                            'Summerdale', 'Susan Moore', 'Sweet Water', 'Sylacauga', 'Sylvan Springs',
                            'Sylvania', 'Talladega Springs', 'Talladega', 'Tallassee', 'Tarrant',
                            'Taylor', 'Thomaston', 'Thomasville', 'Thorsby', 'Town Creek', 'Toxey',
                            'Trafford', 'Triana', 'Trinity', 'Troy', 'Trussville', 'Tuscaloosa',
                            'Tuscumbia', 'Tuskegee', 'Union Grove', 'Union Springs', 'Union',
                            'Uniontown', 'Valley Head', 'Valley', 'Vance', 'Vernon', 'Vestavia Hills',
                            'Vina', 'Vincent', 'Vredenburgh', 'Wadley', 'Waldo', 'Walnut Grove',
                            'Warrior', 'Waterloo', 'Waverly', 'Weaver', 'Webb', 'Wedowee',
                            'West Blocton', 'West Jefferson', 'West Point', 'Wetumpka', 'White Hall',
                            'Wilsonville', 'Wilton', 'Winfield', 'Woodland', 'Woodville',
                            'Yellow Bluff', 'York'
            ),
            'AZ' => array (
                            'Apache Junction', 'Avondale', 'Benson', 'Bisbee', 'Buckeye',
                            'Bullhead City', 'Camp Verde', 'Carefree', 'Casa Grande', 'Cave Creek',
                            'Chandler', 'Chino Valley', 'Clarkdale', 'Clifton', 'Colorado City',
                            'Coolidge', 'Cottonwood', 'Dewey-Humboldt', 'Douglas', 'Duncan', 'Eagar',
                            'El Mirage', 'Eloy', 'Flagstaff', 'Florence', 'Fountain Hills', 'Fredonia',
                            'Gila Bend', 'Gilbert', 'Glendale', 'Globe', 'Goodyear', 'Guadalupe',
                            'Hayden', 'Holbrook', 'Huachuca City', 'Jerome', 'Kearny', 'Kingman',
                            'Lake Havasu City', 'Litchfield Park', 'Mammoth', 'Marana', 'Maricopa',
                            'Mesa', 'Miami', 'Nogales', 'Oro Valley', 'Page', 'Paradise Valley',
                            'Parker', 'Patagonia', 'Payson', 'Peoria', 'Phoenix', 'Pima',
                            'Pinetop-Lakeside', 'Prescott', 'Prescott Valley', 'Quartzsite',
                            'Queen Creek', 'Safford', 'Sahuarita', 'San Luis', 'Scottsdale', 'Sedona',
                            'Show Low', 'Sierra Vista', 'Snowflake', 'Somerton', 'South Tucson',
                            'Springerville', 'St. Johns', 'Star Valley', 'Superior', 'Surprise',
                            'Taylor', 'Tempe', 'Thatcher', 'Tolleson', 'Tombstone', 'Tucson', 'Wellton',
                            'Wickenburg', 'Willcox', 'Williams', 'Winkelman', 'Winslow', 'Youngtown',
                            'Yuma'
            )
    );

    protected $states = array (
            'US'=> array('NY', 'AL', 'AZ')
    );

    //Most popular names of streets
    protected $streets = array (
            'Second', 'Third', 'First', 'Fourth', 'Park', 'Fifth', 'Main', 'Sixth',
            'Oak', 'Seventh', 'Pine', 'Maple', 'Cedar', 'Eighth', 'Elm', 'View',
            'Washington', 'Ninth', 'Lake', 'Hil'
    );

    protected $productTitles = array(
            'Gold Membership', 'Silver Membership', 'Bronze Membership',
            'Platinum Membership', 'Special Programm', 'Latest Sport News',
            'Wheight loss programm', 'Education Course (Advanced)', 'Education Course (Middle)',
            'Education Course (Elementary)', 'Training Programm',
            'Special Training Programm', 'VIP Membership', 'Newsletters'
    );

    protected $userNotes = array(
        'Had a follow up phone call with Joe about the proposal we submitted on 6/3. He will make a decision by next week.',
        'Customer ask for refund but I offer him good discount and he acept my offer',
        'It is important client for us, please deal with him carefully',
        'He will make a decision by next week. Do not call him before.',
        'Need to call on 24/03/16'
    );

    public function __construct(Am_Di $di, $id) {
        $this->di = $di;
        $this->id = $id;
    }

    public function getDi()
    {
        return $this->di;
    }

    public function getID() {
        return $this->id;
    }

    /**
     * Generate user record
     *
     * @return User
     */
    public function createUser($emailDomain = 'cgi-central.int', $added = 'now') {
        $user = $this->getDi()->userTable->createRecord();

        $user->name_f = $this->getRandomFromArray('name_f');
        $user->name_l = $this->getRandomFromArray('name_l');
        $this->setPass($user);
        $user->login = $this->generateLogin($user->name_f, $user->name_l);
        $user->email = $this->generateEmail($user->login, $emailDomain);

        $address = $this->createAddress();

        $user->country = $address->country;
        $user->state = $address->state;
        $user->city = $address->city;
        $user->street = $address->street;
        $user->zip = $address->zip;
        $user->added = sqlTime($added);
        $user->remote_addr = '192.168.1.' . rand(1, 244);

        $user->last_ip = '192.168.1.' . rand(1, 244);
        $user->last_login = sqlTime(max(amstrtotime($added), amstrtotime('now') - rand(60, 3600 * 24 * 10)));

        $user->data()->set('demo-id', $this->getID());
        $user->save();
        return $user;
    }

    public function createNotes($user, $start, $end)
    {
        if (rand(0, 10)<8) return; //20% probability
        $tm = mt_rand($start, $end);
        $note = $this->getDi()->userNoteRecord;
        $note->dattm = sqlTime($tm);
        $note->user_id = $user->pk();
        $note->content = $this->getRandomFromArray('userNotes');
        $note->admin_id = 1;
        $note->save();
    }

    /**
     *
     * @param User
     * @param Am_Paysystem_Abstract $payplugin
     * @param array $product_ids array of product_id to use for generation
     * @param int $invCnt count of invoices per user
     * @param int $invVar variation of count of invoices per user
     * @param int $prdCnt count of products per invoice
     * @param int $prdVar variation of products per invoice
     * @param int $start timestamp period begin
     * @param int $end timestamp period end
     */
    public function createInvoices($user, $payplugin, $product_ids, $invCnt, $invVar, $prdCnt, $prdVar, $start, $end, $coupons = array()) {
        $invoiceLimit = $this->getLimit($invCnt, $invVar);
        for($j=1; $j<=$invoiceLimit; $j++) {
            $tm = mt_rand($start, $end);

            /* @var $invoice Invoice */
            $invoice = $this->getDi()->invoiceTable->createRecord();

            $productLimit = max(1, $this->getLimit($prdCnt, $prdVar));

            for ($k=1; $k<=$productLimit; $k++) {
                try {
                    $product = Am_Di::getInstance()->productTable->load(array_rand($product_ids));
                    if (!($err = $invoice->isProductCompatible($product)))
                        $invoice->add($product, 1);
                } catch (Am_Exception_InputError $e) {}
            }
            if (!count($invoice->getItems())) continue;

            if (count($coupons) && (rand(1,5) == 5)) {
                $invoice->setCouponCode($coupons[array_rand($coupons)]);
                $invoice->validateCoupon();
            }

            $invoice->tm_added = sqlTime($tm);
            $invoice->setUser($user);
            $invoice->calculate();
            $invoice->setPaysystem($payplugin->getId());
            $invoice->save();

            $this->getDi()->setService('dateTime', new DateTime('@' . $tm));

            if ($invoice->isZero()) {
                $tr = new Am_Paysystem_Transaction_Free($this->getDi()->plugins_payment->loadGet('free'));
                $tr->setInvoice($invoice)
                    ->setTime(new DateTime('@' . $tm))
                    ->process();
            } else {
                $tr = new Am_Paysystem_Transaction_Manual($payplugin);
                $tr->setAmount($invoice->first_total)
                    ->setInvoice($invoice)
                    ->setTime(new DateTime('@' . $tm))
                    ->setReceiptId($receipt = 'D'. str_replace('.', '-', substr(sprintf('%.4f', microtime(true)), -7)))
                    ->process();

                //recurring payments
                $i=1;
                while ((float)$invoice->second_total
                    && $invoice->rebill_date < sqlDate($end)
                    && $invoice->rebill_times >= $i
                    && !$invoice->isCancelled()) {

                    $this->getDi()->setService('dateTime', new DateTime('@' . amstrtotime($invoice->rebill_date)));

                    $tr = new Am_Paysystem_Transaction_Manual($payplugin);
                    $tr->setAmount($invoice->second_total)
                        ->setInvoice($invoice)
                        ->setTime(new DateTime('@' . amstrtotime($invoice->rebill_date)))
                        ->setReceiptId($receipt . '/' . $i++)
                        ->process();

                    if (rand(1,5) == 5) { //20% probability
                        $invoice->setCancelled(true);
                    }
                }
//            $cc = $this->createCcRecord($user);
//
//            Am_Paysystem_Transaction_CcDemo::_setTime(new DateTime('-'.rand(0,200).' days'));
//            $payplugin->doBill($invoice, true, $cc);
            }
            $tr = null;
            unset($tr);

            $invoice = null;
            unset($invoice);
        }
    }

    /**
     *
     * @param type $cnt
     * @param type $pcCnt
     * @return array array of generated product_id
     */
    public function createProducts($cnt, $pcCnt=0, $recurring = 0) {
        $product_ids = array();
        $pcIds = array();
        for ($i=0; $i<$pcCnt; $i++) {
            $pc = $this->getDi()->productCategoryTable->createRecord();

            $pc->title = 'Group of Access' . $i ;
            //$pc->code = $this->getID();
            $pc->save();

            $pcIds[] = $pc->pk();
        }

        for ($i=1; $i<=$cnt; $i++) {
            $product = $this->createProduct($i<=$recurring);

            if ($pcCnt) {
                shuffle($pcIds);
                $product->setCategories(array($pcIds[0]));
            }
            $product_ids[$product->pk()] = $product->pk();

            $product = null;
            unset($product);
        }
        return $product_ids;
    }

    /*
     * @return Product
    */
    public function createProduct($is_recurring=false) {
        $product = $this->getDi()->productTable->createRecord();

        $product->title        = $this->getRandomFromArray('productTitles');
        $product->description  = 'Short description of this subscription';

        $product->save();

        $bp = $product->createBillingPlan();
        $bp->title = "default";
        $bp->first_price = rand(0, 200);
        $bp->first_period = rand(1,12) . 'm';
        $bp->rebill_times = 0;
        if ($is_recurring) {
            $bp->second_price = rand(100,200);
            $bp->second_period = rand(1,12) . 'm';
            $bp->rebill_times =IProduct::RECURRING_REBILLS;
        }
        $bp->insert();

        $product->setBillingPlan($bp);
        $product->data()->set('demo-id', $this->getID());
        $product->save();
        return $product;
    }

    public function createAddress() {

        $address = new stdClass();

        $address->country = $this->getRandomFromArray('countries');
        $address->state   = $this->generateState($address->country);
        $address->city    = $this->generateCity($address->state);
        $address->street  = $this->generateStreet();
        $address->zip     = $this->generateZip();

        return $address;
    }

    protected function setPass(User $user){
        $user->pass = $this->getRandomPassHash();
    }

    protected function getRandomPassHash() {
        static $passHash = null;

        if (is_null($passHash)) {
            $ph = new PasswordHash(4, true);
            $passHash = array();
            for($i=0; $i<5; $i++) {
                $passHash[] = $ph->HashPassword($this->generatePass());
            }
        }

        return $passHash[rand(0, count($passHash)-1)];
    }

    protected function createCcRecord(User $user)
    {
        $cc = $this->getDi()->ccRecordTable->createRecord();
        $cc->user_id = $user->pk();
        $cc->cc_number = rand(0, 100) > 20 ? '4111111111111111' : '4111111111119999';
        $cc->cc_expire = date('my', time() + 3600 * 24 * 366);
        $cc->cc_name_f = $user->name_f;
        $cc->cc_name_l = $user->name_l;
        $cc->cc_country = $user->country;
        $cc->cc_street = $user->street;
        $cc->cc_city = $user->city;
        $cc->cc_state = $user->state;
        $cc->cc_zip = $user->zip;

        return $cc;
    }

    protected function getLimit($value, $variation) {
        $res = $value + rand(0, 2 * $variation) - $variation;
        return (int)$res;
    }

    protected function getRandomFromArray($arrayName) {
        $array = $this->$arrayName;
        settype($array, 'array');

        return $array[ array_rand($array) ];
    }

    protected function generateState($country) {
        $states = $this->states;
        settype($states, 'array');

        return $states[ $country ][ array_rand($states[ $country ]) ];
    }

    protected function generateCity($state) {
        $cities = $this->cities;
        settype($cities, 'array');

        return $cities[ $state ][ array_rand($cities[ $state ]) ];
    }

    protected function generateStreet() {
        $street = $this->getRandomFromArray('streets');

        return $street . ', ' . rand(1,200);
    }

    protected function generateZip() {
        return str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    protected function generateEmail($login, $emailDomain) {
        $email = $login . '@' . $emailDomain;
        return $email;
    }

    protected function generateLogin($name_f, $name_l) {
        $login = $name_f . $name_l . substr(md5(rand(1000000, 9999999) . microtime()), 0, 4);
        return strtolower($login);
    }

    protected function generatePass() {
        return rand(10000, 99999);
    }
}