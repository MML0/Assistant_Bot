<?php

use Bot\AdminSettings;
use Bot\Plans;
use Bot\Settings;

$planSettings = Settings::defaults();

test('plans: daily limit by status', function () use ($planSettings) {
    T::eq(5, Plans::dailyChatLimit($planSettings, false, null), 'free user');
    T::eq(5, Plans::dailyChatLimit($planSettings, false, 'ultimate'), 'expired plan is free');
    T::eq(30, Plans::dailyChatLimit($planSettings, true, 'basic'));
    T::eq(50, Plans::dailyChatLimit($planSettings, true, 'advanced'));
    T::eq(250, Plans::dailyChatLimit($planSettings, true, 'ultimate'));
    T::eq(15, Plans::dailyChatLimit($planSettings, true, null), 'invite PRO has a small limit');
    T::eq(15, Plans::dailyChatLimit($planSettings, true, 'removed_plan'), 'unknown plan falls back to invite PRO');
    T::eq(0, Plans::limits(['plans' => [], 'pro_limits' => ['chat_per_day' => 1], 'free_limits' => []], true, null)['chat_per_month'], 'no monthly limit when unset');
    $monthly = $planSettings;
    $monthly['plans']['basic']['chat_per_month'] = 300;
    T::eq(300, Plans::limits($monthly, true, 'basic')['chat_per_month']);
    T::ok(str_contains(Plans::usageText($monthly, true, 'basic', ['chat' => 1, 'chat_month' => 42], 'x'), '42 از 300 پیام'), 'monthly line in usage');
    T::eq(1, Plans::limits($planSettings, true, null)['image_per_week'], 'invite PRO: one image a week');
    T::eq(1, Plans::limits($planSettings, true, null)['voice_per_week'], 'invite PRO: one voice a week');
    T::eq(0, Plans::limits($planSettings, true, 'ultimate')['voice_per_week'], 'paid plans have no weekly cap');
});

test('plans: media limits and tiers by status', function () use ($planSettings) {
    T::eq(0, Plans::limits($planSettings, false, null)['image_per_day'], 'free has no images');
    T::eq(0, Plans::limits($planSettings, true, null)['vision_per_day'], 'referral PRO has no vision');
    T::eq(0, Plans::limits($planSettings, true, 'basic')['image_per_day']);
    T::eq(15, Plans::limits($planSettings, true, 'ultimate')['image_per_day']);
    T::eq(200, Plans::limits($planSettings, true, 'ultimate')['image_per_month']);
    T::ok(!Plans::tierAllowed($planSettings, false, null, 'extra'), 'free cannot use extra');
    T::ok(!Plans::tierAllowed($planSettings, true, 'basic', 'extra'));
    foreach (['basic', 'advanced', 'ultimate'] as $plan) {
        T::eq('smart', Plans::limits($planSettings, true, $plan)['tiers'][0], "$plan starts on smart");
        T::ok(!Plans::tierAllowed($planSettings, true, $plan, 'normal'), "$plan has no normal");
    }
    T::ok(Plans::tierAllowed($planSettings, true, 'ultimate', 'extra'));
    T::eq(['normal'], Plans::limits($planSettings, false, null)['tiers'], 'free stays on normal');
});

test('plans: invoice amounts are in rial', function () use ($planSettings) {
    $p = Plans::packages($planSettings);
    T::eq(890000, $p['basic']['amount']);
    T::eq(1490000, $p['advanced']['amount']);
    T::eq(2990000, $p['ultimate']['amount']);
});

test('plans: buttons map only to known commands', function () use ($planSettings) {
    foreach (Plans::KEYBOARD as $label => $command) {
        T::eq($command, Plans::commandForButton($label));
    }
    T::eq(null, Plans::commandForButton('سلام'));
    T::eq('/usage', Plans::commandForCallback('cmd_usage'));
    T::eq('/image', Plans::commandForCallback('cmd_image'));
    T::eq(null, Plans::commandForCallback('cmd_grantpremium'), 'admin commands are not reachable');
    T::eq(null, Plans::commandForCallback('buypro_basic'), 'buy buttons keep their own handler');
    foreach (Plans::menuButtons() as $row) {
        foreach ($row as $b) {
            T::ok(Plans::commandForCallback($b['callback_data']) !== null, $b['callback_data']);
        }
    }
    foreach (Plans::buyButtons($planSettings) as $row) {
        T::ok(strlen($row[0]['callback_data']) <= 64, 'callback data fits the 64 byte limit');
    }
});

test('plans: list follows the settings', function () use ($planSettings) {
    $list = Plans::listText($planSettings, false, null);
    T::ok(str_contains($list, 'در حال حاضر از سهمیه رایگان'));
    T::ok(str_contains($list, '89,000'));
    T::ok(str_contains($list, '5 در روز / 60 در ماه'));
    T::ok(str_contains(Plans::listText($planSettings, true, 'basic'), 'پلن فعلی شما: پلن پایه'));
    $off = $planSettings;
    $off['media']['images_enabled'] = false;
    T::ok(!str_contains(Plans::listText($off, false, null), '5 در روز / 60 در ماه'), 'switched-off images are not sold');
    $hidden = $planSettings;
    $hidden['plans']['basic']['enabled'] = false;
    T::ok(!str_contains(Plans::listText($hidden, false, null), 'پلن پایه'));
    T::eq(2, count(Plans::buyButtons($hidden)), 'a plan not on sale has no button');
    T::ok(isset(Plans::packages($hidden)['basic']), 'its open invoices can still be paid');
});

test('plans: usage text', function () use ($planSettings) {
    $free = Plans::usageText($planSettings, false, null, ['chat' => 3], 'x');
    T::ok(str_contains($free, '3 از 5 پیام'));
    T::ok(!str_contains($free, 'تبدیل صدا'), 'no voice line without access');
    $paid = Plans::usageText($planSettings, true, 'advanced', ['chat' => 10, 'voice_seconds' => 90, 'image_day' => 2, 'image_month' => 7, 'vision' => 1], 'x');
    T::ok(str_contains($paid, '10 از 50 پیام'));
    T::ok(str_contains($paid, '2 از 30 دقیقه'));
    T::ok(str_contains($paid, '2 از 5 (این ماه 7 از 60)'));
    T::ok(str_contains($paid, '1 از 10'));
    $invite = Plans::usageText($planSettings, true, null, ['chat' => 9, 'image_week' => 1, 'voice_week' => 0, 'voice_limit' => 3], 'x');
    T::ok(str_contains($invite, '9 از 15 پیام'));
    T::ok(str_contains($invite, 'این هفته: 1 از 1'), 'weekly image line');
    T::ok(str_contains($invite, 'این هفته: 0 از 1 پیام'), 'weekly voice line');
});

test('dashboard settings: valid input round trips and bad input is refused', function () use ($planSettings) {
    $current = AdminSettings::current($planSettings);
    $clean = AdminSettings::sanitize($current);
    T::eq($planSettings['plans']['basic']['price_toman'], $clean['plans']['basic']['price_toman']);
    T::eq(['metis', 'gapgpt'], $clean['media']['order']['voice']);

    $refused = function (array $bad, string $why) {
        try {
            AdminSettings::sanitize($bad);
            T::ok(false, $why);
        } catch (InvalidArgumentException $e) {
            T::ok(true, $why);
        }
    };
    $bad = $current; $bad['plans']['Bad Key'] = $bad['plans']['basic'];
    $refused($bad, 'plan keys must fit invoice payloads');
    $bad = $current; $bad['plans']['pro'] = $bad['plans']['basic'];
    $refused($bad, 'old package keys are reserved');
    $bad = $current; $bad['plans']['basic']['chat_per_day'] = -5;
    $refused($bad, 'limits have a range');
    $bad = $current; $bad['media']['models']['image']['metis'] = 'x"; DROP';
    $refused($bad, 'model names are checked');
    $bad = $current; $bad['media']['order']['image'] = ['evil'];
    $refused($bad, 'only known providers');
    $bad = $current; $bad['prompts']['chat'] = '   ';
    $refused($bad, 'a prompt cannot be emptied');
    T::ok(str_contains($clean['prompts']['chat'], '{first_name}'), 'chat prompt keeps its placeholders');
    $extra = $current + ['api_key' => 'stolen'];
    T::ok(!isset(AdminSettings::sanitize($extra)['api_key']), 'unknown keys are dropped');
});

test('dashboard settings: applied over config, deleted plans stay deleted', function () use ($planSettings) {
    $dir = tmpdir();
    $clean = AdminSettings::sanitize(AdminSettings::current($planSettings));
    unset($clean['plans']['basic']);
    $clean['plans']['ultimate']['image_per_day'] = 99;
    $clean['routing']['mode'] = 'paid_only';
    file_put_contents($dir . '/' . AdminSettings::FILE, json_encode($clean));
    $s = Settings::fromConfig(['storage_dir' => $dir, 'gpt' => ['api_key' => 'k', 'base_url' => 'https://m.example']]);
    T::ok(!isset($s['plans']['basic']));
    T::eq(99, $s['plans']['ultimate']['image_per_day']);
    T::eq('paid_only', $s['routing']['mode']);
    T::eq('k', $s['gpt']['api_key'], 'keys still come from config.php');
    file_put_contents($dir . '/' . AdminSettings::FILE, '{broken');
    T::ok(isset(Settings::fromConfig(['storage_dir' => $dir])['plans']['basic']), 'a damaged file falls back to defaults');
});
