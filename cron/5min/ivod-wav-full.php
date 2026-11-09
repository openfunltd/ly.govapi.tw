<?php
// Full 影片的 wav 下載，與 ivod-wav.php（Clip）分開跑、各自有 run.php 的鎖，避免長影片擋住 Clip
putenv('mode=full');
include(__DIR__ . '/ivod-wav.php');
