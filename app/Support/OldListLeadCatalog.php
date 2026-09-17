<?php

namespace App\Support;

/**
 * One-time Old List outreach rows and the matching email template.
 */
final class OldListLeadCatalog
{
    public const TEMPLATE_NAME = 'Old List';

    public static function templateSubject(): string
    {
        return 'Following up with {Company}';
    }

    public static function templateBody(): string
    {
        return '<p>Hi {Name},</p>'
            .'<p>I am reaching back out about fulfillment for {Company}. We help brands with storage, pick, and pack, and I wanted to see if that is still useful for you.</p>'
            .'<p>If you want a quick look at pricing, reply to this email and I will send it over.</p>'
            .'<p>Thanks,<br>Save Rack</p>';
    }

    /**
     * @return list<array{name: string, company_name: string, email: string, referral: string, last_activity: string|null}>
     */
    public static function rows(): array
    {
        return [
            self::row('Justin Evexia Science', 'Evexia Science', 'support@evexiascience.com', 'bizy', '1/27/2025'),
            self::row('Margaret Davis', 'MD Prescriptives', 'info@mdprescriptives.com', 'bizy', '1/27/2025'),
            self::row('Vijay Love, Indus', 'Love, Indus', 'vijay@loveindus.com', 'bizy', '2/25/2025'),
            self::row('Martin Andelman', 'Deltrium', 'mandelman@mac.com', 'bizy', '6/29/2026'),
            self::row('Coy Ntrensik Design', 'Ntrensik Designs', 'ntrensik.design@gmail.com', 'bizy', '1/7/2025'),
            self::row('Echo H', 'Fou Gallery', 'echoyuhe@fougallery.com', 'google', null),
            self::row('Ben Floor', 'Ripley Rader', 'ben@ripleyrader.com', 'google', null),
            self::row('Arjun Varma', 'AV Universal', 'arjun@avuniversalcorp.com', 'bizy', null),
            self::row('Gene Higgins', 'Skin Type Solutions', 'gene@skintypesolutions.com', 'google', null),
            self::row('Tracey Valdez', 'ENO', 'traceyv@enobrands.com', 'google', null),
            self::row('Leyna Topete', 'impromptu.life', 'leyna@impromptu.life', 'bizy', null),
            self::row('Marshall Merriam', 'primalvore', 'Marshall@primalvore.com', 'bizy', null),
            self::row('Mary Bemis', 'reprise activewear', 'mary@repriseactivewear.com', 'bizy', null),
            self::row('Ronald Cort', "Nana's Special Sauce", 'ron@nanasspecialsauce.com', 'bizy', null),
            self::row('Duncan Burns', 'Veggie Dome', 'duncan@veggidome.com', 'bizy', null),
            self::row('Ben Johnson', 'slckr.us', 'ben@slckr.us', 'bizy', null),
            self::row('Cynthia Kim', 'Love Classic', 'cynthia@loveclassic.com', 'bizy', null),
            self::row('Ryan Price', 'Monteaco', 'ryan@monteaco.com', 'bizy', null),
            self::row('Robert Long', 'Protiva Mom', 'robertlong@epbfi.com', 'bizy', null),
            self::row('Sulina Shop', 'Sulina Shop', 'sulinashop@sulinashop.com', 'google', null),
            self::row('Jorge Hoyos', 'Sapiens Child', 'jorge@sapienschild.com', 'google', null),
            self::row('Veselina Chebanova', 'arteanafashion', 'veselina@arteanafashion.com', 'google', null),
            self::row('Nancy Murphy', 'Veracity', 'nancy.murphy@veracityselfcare.com', 'bizy', null),
            self::row('Ram Narayan', 'terra', 'ram@terraxworld.com', 'google', null),
            self::row('Barry ten', 'Wrong Friends', 'barry@wrong-friends.com', 'google', null),
            self::row('Kenny Turano', 'Matterhorn Fit', 'kenny@matterhornfit.com', 'bizy', null),
            self::row('John Lowe', 'Scorched Ice', 'john@scorchedice.ca', 'google', null),
            self::row('Terrie Fowler', 'HealthStyle Therapeutix', 'tfowler@healthstyletherapeutix.com', 'bizy', '1/16/2025'),
            self::row('Caroline Priebe', 'Driftless Goods', 'caroline@driftlessgoods.com', 'google', null),
            self::row('Don Crawford', 'Rocket Math', 'don@rocketmath.com', 'google', null),
            self::row('Dexter B.', 'Dexter B.Jenkins', 'dexter@dexterbjenkins.com', 'google', null),
            self::row('Chase Tinkham', 'KT Automotive Products', 'chase.tinkham@ktautopro.com', 'google', null),
            self::row('Greer Smith', '50 Ducks', 'greer@50ducks.com', 'google', null),
            self::row('Evelina DeoDoc', 'DeoDoc', 'askdeodoc@deodoc.com', 'bizy', '1/16/2025'),
            self::row('Trevor Larson', 'SAMi', 'trevor@samialert.com', 'bizy', '1/7/2025'),
            self::row('Michelle Terrell', 'World Of Smoke N Vape', 'terrellm@worldofsmokenvape.com', 'google', '5/7/2025'),
            self::row('Daniel Tauber', 'Asian Beauty Supply', 'dantauber@gmail.com', 'google', '5/7/2025'),
            self::row('Ash Chandra', 'cupcakes', 'ashchandra78@gmail.com', 'bizy', '2/26/2025'),
            self::row('Mohd Tanvir', 'AR Rehman', 'tanvirt67@gmail.com', 'bizy', '6/29/2026'),
            self::row('Mick Nishikawa', 'Colour By Emma', 'info@coloursbyemma.com', 'bizy', '6/29/2026'),
            self::row('Cristian Gaytan', 'Hemmersbach', 'cristian.gaytan@hemmersbach.com', 'google', '1/31/2025'),
            self::row('Tim Zenderman', 'Cincinnati Washboards', 'tim@cincinnatiwashboards.com', 'google', '6/29/2026'),
            self::row('Janan Rajeevikaran', 'Tcha Tea', 'hello@tchatea.com', 'bizy', '1/28/2025'),
            self::row('Jesse Hunt', 'See Rene Boutique', 'info@seereneboutique.com', 'bizy', '2/27/2025'),
            self::row('Francisco Javier', 'Anime Galore', 'animesgalorestore@gmail.com', 'bizy', '1/28/2025'),
            self::row('Maximilien Pean', 'WRLD KICKS', 'maximilienpean@gmail.com', 'bizy', '6/29/2026'),
            self::row('Sarah Hollingsworth', 'EZ Lift Bed', 'sarah@ezliftbed.com', 'bizy', '1/9/2025'),
        ];
    }

    /**
     * @return array{name: string, company_name: string, email: string, referral: string, last_activity: string|null}
     */
    private static function row(string $name, string $company, string $email, string $referral, ?string $lastActivity): array
    {
        return [
            'name' => $name,
            'company_name' => $company,
            'email' => $email,
            'referral' => $referral,
            'last_activity' => $lastActivity,
        ];
    }
}
