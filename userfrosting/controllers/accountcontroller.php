<?php

namespace UserFrosting;

/*******

/account/*

*******/

// Handles account-related activities, including login, registration, password recovery, and account settings
class AccountController extends \UserFrosting\BaseController {

    public function __construct($app){
        $this->_app = $app;
    
        // Load a page schema.  You may override this in individual pages.
        $this->_page_schema = PageSchema::load("loggedout", $this->_app->config('schema.path') . "/pages/pages.json");
    }

    public function pageHome(){
        $this->_app->render('common/home.html', [
            'page' => [
                'author' =>         $this->_app->site->author,
                'title' =>          "A secure, modern user management system based on UserCake, jQuery, and Bootstrap.",
                'description' =>    "Main landing page for public access to this website.",
                'schema' =>         $this->_page_schema,
                'alerts' =>         $this->_app->alerts->getAndClearMessages(), 
                'active_page' =>    ""
            ]
        ]);
    }
    
    public function pageLogin(){
        // Forward to home page if user is already logged in
        if (!$this->_app->user->isGuest()){
            $this->_app->redirect($this->_app->urlFor('uri_home'));
        }        
        
        $validators = new \Fortress\ClientSideValidator($this->_app->config('schema.path') . "/forms/login.json");
        
        $this->_app->render('common/login.html', [
            'page' => [
                'author' =>         $this->_app->site->author,
                'title' =>          "Login",
                'description' =>    "Login to your UserFrosting account.",
                'schema' =>         $this->_page_schema,
                'alerts' =>         $this->_app->alerts->getAndClearMessages(),     // Starting to violate the Law of Demeter here...
                'active_page' =>    "account/login",
            ],
            'validators' => $validators->formValidationRulesJson()
        ]);
    }

    public function pageRegister($can_register = false){
        // Get the alert message stream
        $ms = $this->_app->alerts;
        
        // Prevent the user from registering if he/she is already logged in
        if(!$this->_app->user->isGuest()) {
            $ms->addMessageTranslated("danger", "ACCOUNT_REGISTRATION_LOGOUT");
            $this->_app->redirect($this->_app->urlFor('uri_home'));
        }

        // Security measure: do not allow registering new users until the master account has been created.        
        if (!UserLoader::exists($this->_app->config('user_id_master'))){
            $ms->addMessageTranslated("danger", "MASTER_ACCOUNT_NOT_EXISTS");
            $this->_app->redirect($this->_app->urlFor('uri_install'));
        }
        
        $validators = new \Fortress\ClientSideValidator($this->_app->config('schema.path') . "/forms/register.json");

        $settings = $this->_app->site;
        
        // If registration is disabled, send them back to the home page with an error message
        if (!$settings->can_register){
            $this->_app->alerts->addMessageTranslated("danger", "ACCOUNT_REGISTRATION_DISABLED");
            $this->_app->redirect('login');
        }
    
        $this->_app->render('common/register.html', [
            'page' => [
                'author' =>         $settings->author,
                'title' =>          "Register",
                'description' =>    "Register for a new UserFrosting account.",
                'schema' =>         $this->_page_schema,
                'alerts' =>         $this->_app->alerts->getAndClearMessages(), 
                'active_page' =>    "account/register"                
            ],
            'captcha_image' =>  $this->generateCaptcha(),
            'validators' => $validators->formValidationRulesJson()
        ]);
    }

    public function pageForgotPassword($token = null){
      
        $validators = new \Fortress\ClientSideValidator($this->_app->config('schema.path') . "/forms/forgot-password.json");
        
       $this->_app->render('common/forgot-password.html', [
            'page' => [
                'author' =>         $this->_app->site['author'],
                'title' =>          "Reset Password",
                'description' =>    "Reset your UserFrosting password.",
                'schema' =>         $this->_page_schema,
                'alerts' =>         $this->_app->alerts->getAndClearMessages(), 
                'active_page' =>    ""
            ],
            'token' =>          $token,
            'confirm_ajax' =>   $token ? 1 : 0,
            'validators' => $validators->formValidationRulesJson()
        ]);
    }
    
    public function pageResendActivation(){
        $validators = new \Fortress\ClientSideValidator($this->_app->config('schema.path') . "/forms/resend-activation.json");
         
        $this->_app->render('common/resend-activation.html', [
            'page' => [
                'author' =>         $this->_app->site['author'],
                'title' =>          "Resend Activation",
                'description' =>    "Resend the activation email for your new UserFrosting account.",
                'schema' =>         $this->_page_schema,
                'alerts' =>         $this->_app->alerts->getAndClearMessages(), 
                'active_page' =>    ""
            ],
            'validators' => $validators->formValidationRulesJson()
        ]);
    }
    
    public function pageAccountSettings(){
        // Access-controlled page
        if (!$this->_app->user->checkAccess('uri_account_settings')){
            $this->_app->notFound();
        }
        
        $validators = new \Fortress\ClientSideValidator($this->_app->config('schema.path') . "/forms/account-settings.json");
        
        $this->_app->render('account-settings.html', [
            'page' => [
                'author' =>         $this->_app->site->author,
                'title' =>          "Account Settings",
                'description' =>    "Update your account settings, including email, display name, and password.",
                'alerts' =>         $this->_app->alerts->getAndClearMessages(), 
                'schema' =>         PageSchema::load("default", $this->_app->config('schema.path') . "/pages/pages.json")
            ],
            "locales" => $this->_app->site->getLocales(),
            "validators" => $validators->formValidationRulesJson()
        ]);          
    }    
    
    public function login(){
        // Load the request schema
        $requestSchema = new \Fortress\RequestSchema($this->_app->config('schema.path') . "/forms/login.json");
        
        // Get the alert message stream
        $ms = $this->_app->alerts; 
        
        // Forward the user to their default page if he/she is already logged in
        if(!$this->_app->user->isGuest()) {
            $ms->addMessageTranslated("warning", "LOGIN_ALREADY_COMPLETE");
            $this->_app->halt(200);
        }
        
        // Set up Fortress to process the request
        $rf = new \Fortress\HTTPRequestFortress($ms, $requestSchema, $this->_app->request->post());
        
        // Sanitize data
        $rf->sanitize();
                
        // Validate, and halt on validation errors.
        if (!$rf->validate(true)) {
            $this->_app->halt(400);
        }
        
        // Get the filtered data
        $data = $rf->data();
        
        // Determine whether we are trying to log in with an email address or a username
        $isEmail = filter_var($data['user_name'], FILTER_VALIDATE_EMAIL);
        
        // If it's an email address, but email login is not enabled, raise an error.
        if ($isEmail && !$email_login){
            $ms->addMessageTranslated("danger", "ACCOUNT_USER_OR_PASS_INVALID");
            $this->_app->halt(403);
        }
        
        // Load user by email address
        if($isEmail){
            $user = UserLoader::fetch($data['email'], 'email');
            if (!$user){
                $ms->addMessageTranslated("danger", "ACCOUNT_USER_OR_PASS_INVALID");
                $this->_app->halt(403);         
            }
        // Load user by user name    
        } else {
            $user = UserLoader::fetch($data['user_name'], 'user_name');
            if (!$user) {
                $ms->addMessageTranslated("danger", "ACCOUNT_USER_OR_PASS_INVALID");
                $this->_app->halt(403);
            }
        }
        
        // Check that the user's account is enabled
        if ($user->enabled == 0){
            $ms->addMessageTranslated("danger", "ACCOUNT_DISABLED");
            $this->_app->halt(403);
        }        
        
        // Check that the user's account is activated
        if ($user->active == 0) {
            $ms->addMessageTranslated("danger", "ACCOUNT_INACTIVE");
            $this->_app->halt(403);
        }
        
        // Here is my password.  May I please assume the identify of this user now?
        if ($user->login($data['password']))  {
            $_SESSION["userfrosting"]["user"] = $user;
            $this->_app->user = $_SESSION["userfrosting"]["user"];
            $ms->addMessageTranslated("success", "ACCOUNT_WELCOME", $this->_app->user->export());
        } else {
            //Again, we know the password is at fault here, but lets not give away the combination in case of someone bruteforcing
            $ms->addMessageTranslated("danger", "ACCOUNT_USER_OR_PASS_INVALID");
            $this->_app->halt(403);
        }
        
    }
    
    public function logout(){
        session_destroy();
        $this->_app->redirect($this->_app->site->uri['public']);
    }

    public function register(){
        // POST: user_name, display_name, email, title, password, passwordc, captcha, spiderbro, csrf_token
        $post = $this->_app->request->post();
        
        // Get the alert message stream
        $ms = $this->_app->alerts; 
        
        // Check the honeypot. 'spiderbro' is not a real field, it is hidden on the main page and must be submitted with its default value for this to be processed.
        if (!$post['spiderbro'] || $post['spiderbro'] != "http://"){
            error_log("Possible spam received:" . print_r($this->_app->request->post(), true));
            $ms->addMessage("danger", "Aww hellllls no!");
            $this->_app->halt(500);     // Don't let on about why the request failed ;-)
        }  
               
        // Load the request schema
        $requestSchema = new \Fortress\RequestSchema($this->_app->config('schema.path') . "/forms/register.json");
                   
        // Set up Fortress to process the request
        $rf = new \Fortress\HTTPRequestFortress($ms, $requestSchema, $post);        

        // Security measure: do not allow registering new users until the master account has been created.        
        if (!UserLoader::exists($this->_app->config('user_id_master'))){
            $ms->addMessageTranslated("danger", "MASTER_ACCOUNT_NOT_EXISTS");
            $this->_app->halt(403);
        }
          
        // Check if registration is currently enabled
        if (!$this->_app->site->can_register){
            $ms->addMessageTranslated("danger", "ACCOUNT_REGISTRATION_DISABLED");
            $this->_app->halt(403);
        }
          
        // Prevent the user from registering if he/she is already logged in
        if(!$this->_app->user->isGuest()) {
            $ms->addMessageTranslated("danger", "ACCOUNT_REGISTRATION_LOGOUT");
            $this->_app->halt(200);
        }
                
        // Sanitize data
        $rf->sanitize();
                
        // Validate, and halt on validation errors.
        $error = !$rf->validate(true);
        
        // Get the filtered data
        $data = $rf->data();        
        
        // Check captcha, if required
        if ($this->_app->site->enable_captcha == "1"){
            if (!$data['captcha'] || md5($data['captcha']) != $_SESSION['userfrosting']['captcha']){
                $ms->addMessageTranslated("danger", "CAPTCHA_FAIL");
                $error = true;
            }
        }
        
        // Remove captcha, password confirmation from object data
        $rf->removeFields(['captcha', 'passwordc']);
        
        // Perform desired data transformations.  Is this a feature we could add to Fortress?
        $data['user_name'] = strtolower(trim($data['user_name']));
        $data['display_name'] = trim($data['display_name']);
        $data['email'] = strtolower(trim($data['email']));
        $data['locale'] = $this->_app->site->default_locale;
        
        if ($this->_app->site->require_activation)
            $data['active'] = 0;
        else
            $data['active'] = 1;
        
        // Check if username or email already exists
        if (UserLoader::exists($data['user_name'], 'user_name')){
            $ms->addMessageTranslated("danger", "ACCOUNT_USERNAME_IN_USE", $data);
            $error = true;
        }

        if (UserLoader::exists($data['email'], 'email')){
            $ms->addMessageTranslated("danger", "ACCOUNT_EMAIL_IN_USE", $data);
            $error = true;
        }
        
        // Halt on any validation errors
        if ($error) {
            $this->_app->halt(400);
        }
    
        // Get default primary group (is_default = GROUP_DEFAULT_PRIMARY)
        $primaryGroup = GroupLoader::fetch(GROUP_DEFAULT_PRIMARY, "is_default");
        $data['primary_group_id'] = $primaryGroup->id;
        // Set default title for new users
        $data['title'] = $primaryGroup->new_user_title;
        // Hash password
        $data['password'] = Authentication::hashPassword($data['password']);
        
        // Create the user
        $user = new User($data);

        // Add user to default groups, including default primary group
        $defaultGroups = GroupLoader::fetchAll(GROUP_DEFAULT, "is_default");
        $user->addGroup($primaryGroup->id);
        foreach ($defaultGroups as $group_id => $group)
            $user->addGroup($group_id);    
        
        // Store new user to database
        $user->store();
        if ($this->_app->site->require_activation)
          // Activation required
          $ms->addMessageTranslated("success", "ACCOUNT_REGISTRATION_COMPLETE_TYPE2");
        else
          // No activation required
          $ms->addMessageTranslated("success", "ACCOUNT_REGISTRATION_COMPLETE_TYPE1");
        
    }
    
    public function accountSettings(){
        // Load the request schema
        $requestSchema = new \Fortress\RequestSchema($this->_app->config('schema.path') . "/forms/account-settings.json");
        
        // Get the alert message stream
        $ms = $this->_app->alerts; 
        
        // Access control for entire page
        if (!$this->_app->user->checkAccess('uri_account_settings')){
            $ms->addMessageTranslated("danger", "ACCESS_DENIED");
            $this->_app->halt(403);
        }
        
        $data = $this->_app->request->post();
                       
        // Remove csrf_token
        unset($data['csrf_token']);
                        
        // Check current password
        if (!isset($data['passwordcheck']) || !$this->_app->user->verifyPassword($data['passwordcheck'])){
            $ms->addMessageTranslated("danger", "ACCOUNT_PASSWORD_INVALID");
            $this->_app->halt(403);
        }        
                
        // Validate new email, if specified
        if (isset($data['email']) && $data['email'] != $this->_app->user->email){
            // Check authorization
            if (!$this->_app->user->checkAccess('update_account_setting', ['user' => $this->_app->user, 'property' => 'email'])){
                $ms->addMessageTranslated("danger", "ACCESS_DENIED");
                $this->_app->halt(403);
            }
            // Check if address is in use
            if (UserLoader::exists($data['email'], 'email')){
                $ms->addMessageTranslated("danger", "ACCOUNT_EMAIL_IN_USE", $data);
                $this->_app->halt(400);
            }            
        } else {
            $data['email'] = $this->_app->user->email;
        }
            
        // Validate locale, if specified
        if (isset($data['locale']) && $data['locale'] != $this->_app->user->locale){
            // Check authorization
            if (!$this->_app->user->checkAccess('update_account_setting', ['user' => $this->_app->user, 'property' => 'locale'])){
                $ms->addMessageTranslated("danger", "ACCESS_DENIED");
                $this->_app->halt(403);
            }
            // Validate locale
            if (!in_array($data['locale'], $this->_app->site->getLocales())){
                $ms->addMessageTranslated("danger", "ACCOUNT_SPECIFY_LOCALE");
                $this->_app->halt(400);
            }
        } else {
            $data['locale'] = $this->_app->user->locale;
        }
    
        // Validate display_name, if specified
        if (isset($data['display_name']) && $data['display_name'] != $this->_app->user->display_name){
            // Check authorization
            if (!$this->_app->user->checkAccess('update_account_setting', ['user' => $this->_app->user, 'property' => 'display_name'])){
                $ms->addMessageTranslated("danger", "ACCESS_DENIED");
                $this->_app->halt(403);
            }
        } else {
            $data['display_name'] = $this->_app->user->display_name;
        }
    
        // Validate password, if specified and not empty
        if (isset($data['password']) && !empty($data['password'])){
            // Check authorization
            if (!$this->_app->user->checkAccess('update_account_setting', ['user' => $this->_app->user, 'property' => 'password'])){
                $ms->addMessageTranslated("danger", "ACCESS_DENIED");
                $this->_app->halt(403);
            }
        } else {
            // Do not pass to model if no password is specified
            unset($data['password']);
            unset($data['passwordc']);
        }  
        
        // Set up Fortress to validate the request
        $rf = new \Fortress\HTTPRequestFortress($ms, $requestSchema, $data);
        
        // Validate
        if (!$rf->validate()) {
            $this->_app->halt(400);
        }    
        
        // If a new password was specified, hash it
        if (isset($data['password']))
            $data['password'] = Authentication::hashPassword($data['password']);
        
        // Remove passwordc, passwordcheck
        unset($data['passwordc']);
        unset($data['passwordcheck']);
        
        // Looks good, let's update with new values!
        foreach ($data as $name => $value){
            $this->_app->user->$name = $value;
        }
        
        $this->_app->user->store();
        
        $ms->addMessageTranslated("success", "ACCOUNT_SETTINGS_UPDATED");
    }    
    
    public function captcha(){
        echo $this->generateCaptcha();
    }
    
    /*
    generates a base 64 string to be placed inside the src attribute of an html image tag.
    @blame -r3wt
    */    
    public function generateCaptcha(){
    
        $md5_hash = md5(rand(0,99999));
        $security_code = substr($md5_hash, 25, 5);
        $enc = md5($security_code);
        // Store the generated captcha to the session
        $_SESSION['userfrosting']['captcha'] = $enc;
    
        $width = 150;
        $height = 30;
    
        $image = imagecreatetruecolor(150, 30);
    
        //color pallette
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        $red = imagecolorallocate($image,255,0,0);
        $yellow = imagecolorallocate($image, 255, 255, 0);
        $dark_grey = imagecolorallocate($image, 64,64,64);
        $blue = imagecolorallocate($image, 0,0,255);
    
        //create white rectangle
        imagefilledrectangle($image,0,0,150,30,$white);
    
        //add some lines
        for($i=0;$i<2;$i++) {
            imageline($image,0,rand()%10,10,rand()%30,$dark_grey);
            imageline($image,0,rand()%30,150,rand()%30,$red);
            imageline($image,0,rand()%30,150,rand()%30,$yellow);
        }
    
        // RandTab color pallette
        $randc[0] = imagecolorallocate($image, 0, 0, 0);
        $randc[1] = imagecolorallocate($image,255,0,0);
        $randc[2] = imagecolorallocate($image, 255, 255, 0);
        $randc[3] = imagecolorallocate($image, 64,64,64);
        $randc[4] = imagecolorallocate($image, 0,0,255);
        
        //add some dots
        for($i=0;$i<1000;$i++) {
            imagesetpixel($image,rand()%200,rand()%50,$randc[rand()%5]);
        }    
        
        //calculate center of text
        $x = ( 150 - 0 - imagefontwidth( 5 ) * strlen( $security_code ) ) / 2 + 0 + 5;
    
        //write string twice
        ImageString($image,5, $x, 7, $security_code, $black);
        ImageString($image,5, $x, 7, $security_code, $black);
        //start ob
        ob_start();
        ImagePng($image);
    
        //get binary image data
        $data = ob_get_clean();
        //return base64
        return 'data:image/png;base64,'.chunk_split(base64_encode($data)); //return the base64 encoded image.
    }

}

?>
