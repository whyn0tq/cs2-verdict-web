<?php

if (is_post()) {
    check_csrf_form();
    sign_out();
}
redirect_to(page_url());
