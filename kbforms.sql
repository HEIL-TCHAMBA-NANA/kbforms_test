-- phpMyAdmin SQL Dump
-- version 5.2.0
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: May 19, 2026 at 11:53 AM
-- Server version: 10.4.24-MariaDB
-- PHP Version: 8.1.6

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `kbforms`
--

-- --------------------------------------------------------

--
-- Table structure for table `answers`
--

CREATE TABLE `answers` (
  `id` int(11) NOT NULL,
  `response_id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `value` text DEFAULT NULL,
  `repeat_index` int(11) DEFAULT NULL COMMENT 'B8 : n° d''occurrence dans un groupe répétable (0..N-1), NULL sinon'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `answers`
--

INSERT INTO `answers` (`id`, `response_id`, `question_id`, `value`) VALUES
(16, 3, 21, 'NDJASSI'),
(17, 3, 22, 'LINE AUDREY'),
(18, 3, 23, '+237 690647692'),
(19, 3, 24, 'Lineaudreyndjassi@gmail.com'),
(20, 3, 25, 'yaounde'),
(21, 3, 26, 'Travailleur'),
(22, 3, 27, 'Booster ma carrière'),
(23, 3, 28, 'Non'),
(24, 4, 21, 'Ebai'),
(25, 4, 22, 'Betty'),
(26, 4, 23, '681097196'),
(27, 4, 24, 'betty.ebai@kbgrouptrainincenter.cm'),
(28, 4, 25, 'Yaounde'),
(29, 4, 26, 'Etudiant'),
(30, 4, 27, 'Améliorer mes compétences'),
(31, 4, 28, 'Oui'),
(32, 5, 21, 'AYELE'),
(33, 5, 22, 'Lydie Elisabeth'),
(34, 5, 23, '689306990'),
(35, 5, 24, 'lydie.ayele@kbgroup-trainingcenter.com'),
(36, 5, 25, 'Yaounde'),
(37, 5, 26, 'Etudiant'),
(38, 5, 27, 'Améliorer mes compétences'),
(39, 5, 28, 'Non'),
(40, 6, 21, 'francois'),
(41, 6, 22, 'xavier'),
(42, 6, 23, '6948122'),
(43, 6, 24, 'eeee'),
(44, 6, 25, 'yaounde'),
(45, 6, 26, 'Free-lance'),
(46, 6, 27, 'Booster ma carrière, Trouver un emploi, Améliorer mes compétences, Mieux récruter'),
(47, 6, 28, 'Oui'),
(93, 18, 115, 'yes'),
(94, 18, 116, 'merci'),
(95, 19, 115, 'ok'),
(96, 19, 116, 'okay aussi'),
(97, 20, 115, 'gfuiopiuhhbjn'),
(98, 20, 116, 'gjhkjh '),
(99, 20, 125, 'Option 1'),
(100, 21, 115, 'gfuiopiuhhbjn'),
(101, 21, 116, 'gjhkjh '),
(102, 21, 125, 'Option 1'),
(103, 22, 115, 'bngfgvuh'),
(104, 22, 116, 'jgk'),
(105, 22, 125, 'Option 1'),
(111, 23, 144, '3'),
(112, 23, 145, '{\"country\":\"CM\",\"number\":\"698526483\"}'),
(113, 23, 146, '{\"CPU\":\"CPU\",\"RAM\":\"RAM\"}'),
(114, 24, 158, 'hjhkj'),
(115, 24, 161, 'guhiok'),
(116, 25, 158, 'gh'),
(117, 25, 161, 'gh');

-- --------------------------------------------------------

--
-- Table structure for table `conditions`
--

CREATE TABLE `conditions` (
  `id` int(11) NOT NULL,
  `form_id` int(11) NOT NULL,
  `source_question_id` int(11) NOT NULL,
  `operator` enum('eq','neq','contains') NOT NULL DEFAULT 'eq',
  `value` varchar(255) NOT NULL,
  `target_section_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `forms`
--

CREATE TABLE `forms` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `banner_data` mediumtext DEFAULT NULL,
  `is_published` tinyint(1) DEFAULT 0,
  `share_link` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `share_password_hash` varchar(255) DEFAULT NULL COMMENT 'bcrypt hash du mot de passe de partage public (NULL = accès libre)',
  `confirmation_message` text DEFAULT NULL COMMENT 'Message affiché après soumission du formulaire (NULL = message par défaut)',
  `theme_color` varchar(7) DEFAULT NULL COMMENT 'Couleur principale HEX ex: #4F46E5 (US-026)',
  `header_image` mediumtext DEFAULT NULL COMMENT 'Image d''en-tête : URL ou data URI base64 (US-027)',
  `font_family` varchar(100) DEFAULT NULL COMMENT 'Police ex: "Inter", "Roboto" (US-028)',
  `font_size` tinyint(3) UNSIGNED DEFAULT NULL COMMENT 'Taille de base en px ex: 16 (US-028)',
  `version` int(11) NOT NULL DEFAULT 1 COMMENT 'Version de structure — incrémentée par triggers (questions/sections/conditions) et par ThemeModel (thème). Cf. migrations/2026-09-02_mobile_form_version.sql',
  `response_retention_days` int(11) DEFAULT NULL COMMENT 'Purge des réponses au-delà de N jours (NULL = illimité)',
  `require_captcha` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Exiger un CAPTCHA sur le formulaire public',
  `notify_emails` varchar(1000) DEFAULT NULL COMMENT 'Adresses notifiées par email à chaque nouvelle réponse (séparées par virgule)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Triggers de forms.version (prérequis M1 mobile) :
-- toute INSERT/UPDATE/DELETE sur questions/sections/conditions fait
-- UPDATE forms SET version = version + 1 WHERE id = <form_id>.
-- Définition complète : migrations/2026-09-02_mobile_form_version.sql

--
-- Dumping data for table `forms`
--

INSERT INTO `forms` (`id`, `user_id`, `title`, `description`, `banner_data`, `is_published`, `share_link`, `created_at`, `updated_at`, `share_password_hash`, `confirmation_message`, `theme_color`, `header_image`, `font_family`, `font_size`) VALUES
(5, 9, 'K&B Group Master Class', '🎓 MASTERCLASS K&B GROUP\n\nÉdition spéciale – Fête Internationale du Travail 2026\n\nÀÉdition l’occasion de la 140ᵉ édition de la Fête Internationale du Travail, K&B Group vous invite à une Masterclass exclusive autour du thème :\n\n« La certification professionnelle : levier stratégique de développement des carrières et de la performance des organisations au cœur du capital humain »\n\n📍 Lieu : Yaoundé (Descente Avenue Germaine) & en ligne\n📅 Date : Jeudi 07 mai 2026\n🕒 Heure : 15h00\n⏳ Durée : 1h30\n🎯 Accès : Gratuit (inscription obligatoire)\n\n👉 Merci de bien vouloir remplir ce formulaire afin de confirmer votre participation.', NULL, 1, 'http://kbforms.local/f/849329378ad1', '2026-04-28 11:54:06', '2026-04-29 08:17:53', NULL, NULL, NULL, NULL, NULL, NULL),
(20, 7, 'Vente de voiture', 'Quels type de voitiure vous plait', 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD/2wCEAAkGBxMTEhUTExMVFhUXFxoaGBgYFx0YHRgYFxcXFxcaFxoaHSggGholGxcYITEiJSkrLi4uFx8zODMtNygtLisBCgoKDg0OGhAQGi0fHx0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLf/AABEIALcBEwMBIgACEQEDEQH/xAAbAAABBQEBAAAAAAAAAAAAAAAEAAIDBQYBB//EAEgQAAEDAQUDCQQGCQMCBwAAAAEAAhEDBBIhMUEFUWEGEyJxgZGhsfAywdHhFCNCUlOSFRYzYnKistLxJFSCQ3MHRGNkk8Li/8QAGQEBAQEBAQEAAAAAAAAAAAAAAAECAwQF/8QALREBAQACAgECBAQGAwAAAAAAAAECERIhAzFRE0Fh8FKBkaEEFMHS4fEVIjL/2gAMAwEAAhEDEQA/AA6uz+coc1fAIqOMxhi52GfFGbQ2WKlnpU78OpgC8RgcAN6z7re6COh7V4C7heJme8oSrtio0CjUbTczIADCJWd3aei/5Z0QaDXg40wB1yW4hWVpoNqPY+GummBBGO/DFCco6LXWNxEdFgy0xarbYtBtQ0awZi2mAHRlIy4StcctQ5QBQp0S8NDGB94gCMZbidVLarGQHuABc1zsYOhkaqSx2RotReM+cce0iJ7lU7X5SvZUqU6d0i+SZznWO5S7J2is/wBIvC63L7N04LRbEt1VzjTqMugCdc1jqHKKs1xdkSZIAnXVajYO2haans3XNaR1yRiFcrbNEx120V5IVDxTIO9MGa87oVvcbjhvELMbHuudd5pw6NTGfu4eK0tr9k9SzPJ+n9d7P432t/BdPH3tnIRsNzS8lrCzoA4mfanDshaKyVDGqzfJ2nDjhH1bNZ1ctBYxAWfJvk1jriMvGMys/wAoXCad6nznSy3HHFX4KzvKdk83hPTGsb0xncS3oJbXUxRpE2eQab4bPsgES3tV7YDg3CMBA3YZLPbQpTZ6PRH7Or9rjvV/ZjAb1DyC35Oozj2PJ61HaHkiMcl2ZxlRVz1rntvTN7McHvLTTIwqYz934p+xrrnyGObDQcTPtTh4JmwGfWOMR0a32p1CI5P04LsI6DNZ3rrlJrbGN7aCjlqop+s1yCKYMEP9t3UFx06JLd+zd61C5TySt5+rPZ5hNacF0xc65SzKLohC2fMo1mSQCbaH1FX/ALb/AOkoezUYpsAM9EY78ETtsf6er/23eRSpNAAGgHuUvc0KPb4+rPG5/WFmNmsm0UgfvT5H3K+5ZOPNRoS3+pZ/ky3/AFbeDj/Qus9GdtucTHFFOwaZTaYF7HMnBTVGzIK4ZercD0HgNCSmZQAEJLF1tqR4/aa5ZJAnGAdCQu7Cpc9XbfcBBk4bsQOPUtzRsdCR0RvwcCPBqYalFuHNRvw39i9M257E7Yq0/oVVgLiebw6MA6ojknVAs1NvTkgE4+XBVdsq37NWAouHQIk44RgceCO2A6uLNRu02n6toEkZR5rptnSwrUrPTvOLagJdM3sSSNFnrfsWz1p5kvZVcZN7If8AI4YnzV+wV3NBuDMnFwwOSgqWC0PbccAGHMB+fas7i9vN27Pq88aBi8DB6Ujv1Wu5F2dtOtiX33NIALLrYBEwdUYeThvXebZI6QJe4Z8RqjLJsyvTEsbTBGOLyfE4rN7VoCxMp0iqBm27SHXXU2HiPmU+htyu8kMYyRmCY8iuel2trayGnqVDsezOZUD3BobNTXHpZYI63G1PAF2kO07lRP5MVs77pOP7TBax6WrTZ1BzDLrmLQOiZxBKurK2RmshZ+T1pBDg+CMf2hPuR9KwW5gEVv5h72qZd0l01BCodvUHvuQy9Dwezfmom2W3fiD8w/sQNs2FannpPEz+IdeoBSdHqMtNlc6jTbcbLWvBB0vHCMVa0HjoiCMhwmFnH8l60npjPCaj0ds3Y9optF1zCZnFxcFrK7J0vyI1UdY4TJwUFOnavtGj2AptqoWlwwdSHWCs8V2othvDXEm6BdqiZ1ccNUTsytzczdxDB0SDlM6oJ3I5xJM05Jn7WqZT5K1aTg9nNkjfK3cppmRqrLbgYz7kNUtZ50xJEDsPFCitami6KbRH2icPJAVqdqvBw5vp59g1XKzfzblXdstXRxMCR5pUrUC2ZB6lU1Nn16gIcKcHMXih3cmqwGF0cA9wCuOpPVL201lrDEqwpvBCx9PYVpAwqBvC84qKts+u0gG0DgJOnanOaTVa3bJ/09SPuO8k+pgezFYt1ofRpvvvc8FpAABOJIxkrQHbVMi86Rj7MYrGXmwk3tFbyugtj95vvKqOTllAtLSCTN4/ywj+UNdto/ZuAEg9KRkDwQezJpVA4xgDiDOYXT4+Ex3uM/NtbLBM7ifXgnNbjJVLszaoDi04yC6AJ68lYWTaTHeyZzXDHyctX3dYkqWgAkSBCShfWpyZDZ7EktVVinVJB5qM5EuM7siUXZbMWue7mRJMjox9kanjKc1r/wAU9y4aLj/1XL0az9nPcM29VLbPVPNkdA6CMRCz+x7U+pY2FktuPDJaYkNOeOeHBEcrHOp0TLnPDjBBMcdFT8jXh73UwCGxe9omTgOxW458L7pubbDYr6xpzevdJ2cb8FY0KlY+00DqIPvVcyyU2tugNbjuz8U2jREQ2s6NIjDwWNeSTWl5RYxUNaJbHN677yKNnrRF9n5VT/QQTPOPnr0Tv0f/AOrU/MpZ5b8v3NxNX2YQb5diB5cFVUa1epWFN1xuBdIaJMZSe1HnZg1q1PzIWlsFjXXm1Hzjje3rMx83c1+/+Et79FqaVbUtPVAPimvqnI1GtO4wCmMsv7xPWnmyN3DtASePyfda5RC2p0g0vF26elIzkYYKUMjKsPNRHZNM6d2CczZTBoe0lX4Wa8okfXLRg9pO44KH6VUOrB7vBSjZrNy4dnMj5lPheT6HOBHV6l8S+9EmYgA5ZalKxVHPc+9WIIOAgZdi5bLPZ6TbzyAP4j4RmqOrtmwgkhpmMy6J8SfBXh5rNdff5OfLtqel+PI7AgrUX/7iOF4D3LMVuUtkGVMH/m4R19DyUf622f8AAb+d39ifB8v0a54tGDiAaxJz9v4BOrWyML7uPSw+KzjeVNnJk0B+d39imbyhshzon8/xhT4Pm+Wkucq8ttsh7b1Y3ZyBwyK4+1xB5xpjKcFX2faFifjzZ72k9weT4Kwa6x5OhhOlQOZgf4owTPxebK73IY56do7SIOMHqIRB2wS3AN/MpaOybPHRaCDuMp7dk0RMMic81j+X8v4o1zV7trOOF8dTfih6z6rwS0tGsloOitTsWj+H5pzNl0xk0jtK14/Dnjd27S5biht5Bovh7CI38RqjHsbq4efkpbbsCmabgxhkxqd4nwU42ZRGTI71xy/gblNXRuK02O9ke7BDWqxtYYc9rZ0vSfktHZthNqCWU70fve4lOPJg/wC3PrtXP/j8vlZ+tS1RWGzF8sFaGNGIa7fvhVlqphjyGOwBwIxy6lrDyV/9ufL3prOSjh7NAjqMe9dvJ/C+TPHGWzr5oxT6mJ6T+4pLe/q9V/C8R8Uln+U8n4ou77ss62W04XaQ4yPgn/SLZMjmh4+5FNTgV9PhHPlQpq2wiHOpHsn3LjfpYBuupDqEe5HApwKcTkCcbWc30+75Loba9KlP8o+CNlK8nGLyoJ30z8Wn+X5KVr7V+JS/KUReSvJxhypjKtfU0j+YKYWiphIZnj0jl3Jl9cvpxOQsWw/cH5z/AGrv0w/ht/Of7VHRs7nY5DefdvRDaIGQvHe74Zd8q8U5HUq73CRTaOJeY8kPbNsU2D7zpiGzE7gTiewKK10KlT2qjWjdKgZYKbcTVExGAjDvWpjGblVJtvaNa814c9lMmDvaT7JBki78VPbeU1OncD2uc5zZwdJ3SZGEkHuRdtFnuuD33gRGJ03CAVj9rbOLnNc0Ei7ALmzIBMdv+dUykMbU3Ke1i1NaaYqMeySA6QHtiSG6F2AIXOR2zLPWokuo06j2nEvc7I5YDDQoSnZKjYgNwxyiIy0R/J8OpVS9nNhjvaBe1pE4mGk4wcsMQs66a2vmbEpD2bNZBxLCfMIltneBAFBo3Cn8lVWza1rk81RYW6EvYSewPgeKM2NabVVJZUs9x2HSvC7E4zBJBjdms6OVVW0NhGpUutdZy44hgaGuyxybMao5n/h9Tug1Ic/WHuaBwboesrVbO2XTs99wxe8y95zPAbmjQDzxTm2q87A8I68j4FdMcGblWIfyNpPqCm29Tdli52EakzHhqtNsbkDZ6XtvqVXcXXQOxkT2yiG1L7nOa3Log/ezke+d5KsNnVxrn1Zx71rj7MXKmVuT1NoJofVP3guLT/Ey8ARxwO4hUFro2hjoqVn0gTDXSXsMmAA/AgnDouAOMAuzWxbaAdD2qO0BrmlpAIIggiQQdCDmEuEvqk8ljJmx1/8AdP7j8Vz6HX/3VTu+aItlI2aXAk0NZxNHjObqfi3iPZlL1zuEjtM99q91hrH/AM1V9dqcyxVAZ+kVfD3o0lR85xCnGLyqWzV6jDIrVJ3w0HvAR9PblQZvee0KpL0xz04xOVXZ287V1Tv+aedsg51ag71ni9RuqJqG60v08H/qv/N80llucHoJJqG0ojcntAQTanBSCrwTZoY0LsDchLx3J18ps0JwU9Gzl24Djj4BY/8AXLmyTcZg4tkhz8RpgOCnPLisD/0BwIcPN61LEsrc0bLQHtY9fRHcjG0KByazs/yvOa3K6o+L9KkYyi8P/sQm/rLMTRjqeceHs4K7hMba9D+hUdGnvJ7011SkPYptJGZ0EZ4lZFvLYGA6i4NAya8EHrkAnqy69JRywpk+zUaOAafJ0qcsXT4Hk+l/OX+onam36kgWd9N5B6QLTUEYRFx14H+KOpHbFt1eqfrrNSa2MHh2uEC6Qc5+9oqp3KKzO9skwQRepuMEYzMGD1I9m3bMcqoH/IDdoY4qyy+lc8sMsf8A1NL9rGAzdbPUPAKXn28O4KiZbKbh0agcetp8nHy+Cc+0EYQ4k5AD35DtIWmFy6s06DuCGtFakBL20wBmSBA7Sq5zKjvaeKY3DF3e7AdUHrQlXY1JxDn1XOcMicSJ3E5dignO2dnk/trOD+68E+BRVnt9lrdGjWYXDPmwHHtwJHggW2SzU3AESf35d4ZeCtrPXEfVt6OkCB2RPkppdhrVsJtQ9LpDUuAJ7Mc+JHYj7JYm02hlNoa0aD1moxWqExNNp3G849gIapXPuiXVD2AAeMnxSTRbt2tYi72iAOuEqez6bRgR4nf8UDaNotb/APqT5pUdqkuAEQRIIyw4rc2zdJa7rNRAvVLt7IZTloBO5DstVmd+ztDQ4GYLgceomUFymq0nsl9MPeJugYOg+1dIIjIHdgshaNnEN5xjHhv3iZLTli2BhpM/FTLK41ccZlHpsuOV09R+K509WE9o+K8tpMrC62mXNeYgMeZIENkx9mMZPvWns+0rVSBvsc4b8HR+WSR1x1qzP6M3x/VZ7dsNasLgZ0IxBI6Uqp2RsWvQp/6is3m2YgE3sBkDhGOG/Gd8Id3KdxcQ99Vu7mwwAxuFRji7scgq1Rtcnn7TUqMk9AFtIAYwHEwT1gDJS6vbUlnQDYlnr0C51J9N0klxDySczp35FXtq5WBlBj7Q1oqvOQBEN/eiSTw8ohdpbaosp8xTaGMc1zA2mQ72hEkRGuczO9Zm02Wk+kQ9ovmSDJloEAXdAJI0x6gs+jU7bihaGvaHNILSAQQdCnOWa/8AD+sTZ3MObHlvkfetOstaREpjlK4cFG9AyUlyOC6gHbTO8eKkZTO8eKiY5TMUEraZ3ju+aC29aDSoVH3hIaYw+0cG+JCMlUnK6eYG7nGT1dI+YCQZqvsqWtbTaRdN5zyQWhkdEnUOknDXPJQ1tlX3U2U5MAl/RLbhwEGcMYwVns/ab6bLjhztEggtObQdAd3A+Cu7Fa6D2htLDSCYIgQJJOOQGa1xi3Nkq+wqjMiY68EK6xVR/gL0qlZcMYSNhacwnFnkwmybHTc1xtFY0zMNDRPWXQMB1blafoizH2La3qeQPMgq/q7KpnQJ1o2TRdTaxtMB0y52pOIABM4RpgJ0V0m2Lp2R77Q6jTLYAwfJuOgAm66Dv36FS7RsNSg0ue+iQIwD5cZMYNwWidyYpnd3KJ3JZvBTS7ZEbRbr5f5U9La132XlvUXN9y0h5KDgns5HN1ITRuKahynrtxFZ0byQ6eGMlG/rlUaJqNY6dS1wPe1w8kE/Y7RanMJNykRMNJN0gGYH8Ug6oi0bCNYto08QDBfdu/VtzeRnjGG8nridt6ixsXK6iCHPsxOH4gPaBUEnvV9Q5bWUw0uNPDAObAjT2ZELB7J2aHl1Rz2MwlocYmMboneMuqE6tsoVKzJF2m0B7iMIaTg3rddIHacgVZbC4zv6PSDtZlRo5twfiIIIOM4ZaoWu57pLoI3vMDuErB7O2W0OdXZIbJDGg5z0QT/MR/CFZWfa1RgIJLhlBx7RqFqZT5udx9l5XrNEQbxkSA2Bjh9qfJDOtHNPbUzpybwyODT9mfaEkYbh1qqpW2ZLmzGvWcAZ9YIXaVsrVBzVBpc8SXvGN0EQGtOWWp/wufskx91xaLY+pXBBuhzcoDiMRE9mm+d6Ls1ueCWXXOIGJy9qcjEEZjsXnVnt9ehVBcXFwOLXkkEbsfMLeVdpmrcqsdcp3RdwzJxIjXd2Lnb7umvZf7Hp0s8Gnj0dww00GWamZbBzrqZpvAacXkENymZcBI4jDrWJslo5oEMJzOpxBmREwBG5OtW031MHuLgNCYHcM1ueTUYvj3V3t7aVnILWtbUcdYww3u18VnqVkcACW6ZvEDHHAOSbbnN9mG8QAD35+KidVJMkknecVjLK2t44zGOVzALnEmB84G4KKxWYuLyS0lwDAA4OIF0mSGyW9KcDjio9qvhg4kDx+StNlWbnBRZUc0Q8uugxzob7MiM5zPDuRaO5F2e79IBjCuf6WnDvWkuDcs9yWry60ycTXJ/laJ7wr+8kZpGmNyY6mNycSmOVDeabuSXElAG0qdgChDVKN8IJL2iD2w+7TktDmzDmnUH3zCNAQe3R/pqvBs92KDKCyBpLqBLgc6bvaH9w6kJzYJvU8HfaYfGPgn1C6SL2WhAj12rrqxdAey+cAD9rPQ5jvKco38LKzlJv7+fsN2ZZXVLwp1XMdF5g+yRqCBjK1T+TtVgEWvGYILL0uBAIEY5kDtCyOy9o0mVqTulLHy8EiS2RIBgYxOi3zds2Ko6+1zmPJDiSQd2geCMQDhGIWo51WnZ1sBgOov6w5p08cR3qN7bY3Ozh38Lx71qHWmi9hYy0MAvl4vMc0yXmpi4mIk7tAuiwhzgWVqdRs3i0PaTJc0va2Tk4GpmR7ULSMp+kazfbs1UdTb3kEv1kpNMPD2nc5sHulbits10VLrCycWRBgXAIgH75eTGgwxhefcvQ36TTlpaaVBpuk3iH1SSQTrAaMeKlB/61WYD7f5fmon8sLPoKh/4j3uWFrVAu0dl1auTSG7zhKm6vGNJtDlFYqjg91FxeMA4OuOjdLHTCTOW9NgilZ4GftHE7z0cTxJVVS2A0e1UYOrH3hTssllbm8u7PknZ0hq8oSXE07OGFxk9JwBJzN0OABOpAxTW07RWxqm5TEuOg4niTvzO9WVC0Uh+youcRuE95xKhtm0TIktkYta03g0jJz3ZFw0AyOJygl3aJrPDQ1gwu5jccMOsDA8Z3oCuXYwCOl5gYIenVUzX8TBwI9eaxa1IIptgO1LzDQcQDOLoGgzXWXBDJhuN0Zl5aJc92U5YE8OwapWx3YQMcvXvQVgrc499SMGtc0HS7Fxsdr/FWJrZ+2GCo1zRi6m5waYggtmWmMCHXTBwxgbpZsGvNG6fsuIHUYd5kqwqUXPc8tbMTJDdSG1BeI3EtOKrdj0wBUGgeQOwIDX1NygLjKlqDs9b0IbS0ZYqKJYJUri1okkAKqrW9w1ujhmoKtppzhzjuJAHdJMdoKAja20GPAa2ZDgZIgQJ7dVojXY11jAEuYznC791xMj8oc7/isU9xOTIHAEntOvgOC0ex61V1I0ywAEAXyOkWjG6J0ViVf7FNNgNwk3yXGYzOJ0yVuy0KjslmDRgrCm8oDxVTr6Ck6qQFQE3+CSgvpIE0J4w9eSjAT2lVEo7FDb6HO030yYD2ls7pESngrpduQeZWx12o8GR0jwnFcFoN0xnGmHktjynsE031GNF9okgiQ4DPDfHksRRtdOQbpYd7TI7QdEVPs+3NGD23mulpBxIdmHNccR1SphbQN3bj8EBXa0novb1QWY7xMjxTbRxAxx3+IPvQXFG3tnCJ64RbdqEYX3fmn3rK03NmbpB4H4gqZzZM33Anh8CE2abKybfqtwZVI6hHi2FXbStTnPdVqXnlxEudMG6ABLnHcN6oGXgfbB65+CnqTVeRncZLW5jQkgHrV2aX9lrVawPN0qED7pgYbyw4nrQtS1ukginI3if61VWe0EMviGVGGA5ouyNzgMD/AIT3bRdJIcccYTa4yb/7eg02l33aX5aXvThbq2jo/gLG/wBKAbtF2/8Alb8E79IO1g9bR7lneX3/AKddeH3v6T+6CK9qqEfWOfGl+YnhKjlD/TD1dQjPQ7wuU6sHDLUfD4KuQxpRNN3H1xUTACJGSeymgVM3nezenJsxezhs6EwBvxVlaHCnRIc1rZOLGNGJguhzyXOdiCcSBhlOWbtBlgG+54ladlRlylTtFR0kX/Zm9Bc2XYi90YE8DOaE1vtPycmox9Z9NrGENa2Jv1XgBokzg2GgkDDDXBUuw6JcKnB0k9TGk5LavLHUy5oinTb0P3i5rCXdzo7CsPsO0MDaoqOLQXghwiR7QOBzwAy1AVrMrVWPYFIgPeb8iQAej81j9vW5tSqW0GBrG9EEDOM3cZOp0AR9fa73NNGm7oEQXwWlw4D7MjA+iuWLZmGSixSUdmudiVb2LY29XVGxgIynRU0uwVCwAZBFtoIplLsKeGqogbQUnNbvFTNXYQDkQnNO5TEKBzNygcB1LqZePox7kkD8EphRBxXWuVRPf3Loqawh3OmPgo6lRAS+qFkLfyds94uD3tE+y2CB1SMAryu7iq20MJUajLbQsQB+rvERqQT4AYIcg3YIOBWgr0TuQlWjwQUpSa8hWD7MNwTfo/BFDh6eysGuvXntcIgtg6DQqT6OVFUsxnRES1Hh5kuP5A0dzXJhoj8RvaHDyaVGLORquuYfD3IJG0N1Sn3uHm1ONB33mEf9xo8yEI0FOJKGhP0Z/wC7/wDIz+5c5l4zA/M34qDMdSbIVB1nruZpIOYkHtGKsKFtYcLwHXgqK7uK6xigsrGyXtb1DDtHnC1/KU0xZrLTeIcZIeM6fGNWyQCN06hYllUsLXDMHDrkEeIWwqMZbXMqGo1jWsDSCR0TJLujGJk4ThAC1GaebY6lYXsqCHB2Goc1wJF06iS7EbgsbZrMTC0m0ntrObRpTzNPAcTrG4cMupE2PZYClWA9nWHeryjRAUtKjGin5tAxrepPDMU7DVSNx1RDSMckgxSRxTZOU5dUopoak4Lumaa4z/hAwlcLinAjDH/Heo6h9ZqBriuJOeZ9FJBEWDd3GPLtSu6R/MexP9eguA9/Ug5GsDhJnzTHN/dHrqUjRvXZ7PX+VFCmiYy7A75SE36OdcUcB1LpZ2+vFBVPsx3Dv+SgfY+Hcrq5n5JhpnXw60FC+w8PL4qJ1hj7K0QpTokaOGIQZh9id93x9YKJ9jO5ah1m4es0x1hnTBBlXWY8FC6zFaupYAm/o2dEGTdZDuTHWQrWu2ZvHYufovgqMkLGU02M7lrP0Yl+ilBkvoRC6LK9bBuyxuU9PZg3KjE/RahwuyrHZ+w6zz0jcbqZxI6lsKVgA0RLaI0RNq+wWBtMXQPee1HCh1qW5C7PFUNFLTHvOnanGmBp4Jw9eintCBgYOGHBdLRqPWunWuvTmneiGXBMgAevFcqY4z5D3JxO9cJCKHrU3fejsn3qME6u7gPHtRT8OzBMhQQPbx8J8oUZc8D7J3ZjPvUz2xK5G6Z16s+1BG57vujsKS6O7sSQRA4p19cPcpBmg60cEiJ9ZLhO71KeEDruqR6vXYkx6eT8zmgZCThpr6wTolL0N+PYgY4AdXr12JU8Rl2Hd68k8tjr9EynA6evBA1rQV2569esE+N6duI3euvEII7icKQ9fJdGPbwGaeBCCAUtT5eScaQ3d/rBTevDLFcI7D54dyBnMj11JnN8ERklABPrRUQimFJcC7MeS6wZIjgYuXfXhH+FL4prggbGGnrBNGGPrzUl3j69SuPOGnrMIGgevXWlGPr1uTrp9SuO+WaBOHr1qmjd6yGoSI4evguT2IpFsYgrjtxTg4eCjKg5Hr1omPbjmMutOe86eveuAyJOeE+uxBzHHsn1qhyD8O1SncPeE3fkCga/PEY93uSTXO9QD5hdRTeKmGKSSI7rpx1SAndhwSSQPAHenXYA45da4kgQOmWaWRjtKSSB7R4JzPPBJJBwEd/kugccvXvXUlR2BkJ3YpxiSMjHu+SSSDrdFwDU93WEkkDgOsD54LsYa/5x8kkkHOrMQe/JIPzSSQOH+e1IDxSSQcdjgmOOXHXL0UkkHGvImRpMHLuSvYToUkkEQqgkjUfFJxxGOeXakkgT8vWkfFRkfL3pJIGtPwXbvy68F1JQMqAgScN/qVG+Yn16xSSRTS470kklB//Z', 1, 'http://kbforms.local/f/105c4549ba45', '2026-05-05 14:55:48', '2026-05-10 14:56:55', NULL, NULL, NULL, NULL, NULL, NULL),
(22, 7, 'Premier formulaire', 'Teesst', NULL, 0, NULL, '2026-05-10 20:06:10', '2026-05-10 20:06:10', NULL, NULL, NULL, NULL, NULL, NULL),
(23, 7, 'yuyu', 'yu', NULL, 0, NULL, '2026-05-10 21:30:09', '2026-05-10 21:30:09', NULL, NULL, NULL, NULL, NULL, NULL),
(28, 12, 'tesst', 'test', NULL, 1, 'http://kbforms.local/f/5d4647db9333', '2026-05-11 20:37:21', '2026-05-15 07:13:27', NULL, NULL, '#5d7462', NULL, 'Roboto', 19),
(29, 12, 'deux', 'tadaaa', NULL, 1, 'http://kbforms.local/f/73ba2536fea8', '2026-05-13 07:07:12', '2026-05-15 13:13:03', NULL, NULL, '#0b084a', NULL, 'Plus Jakarta Sans', 17),
(30, 12, 'Premier formulaire', 'Premier Teste serieux', NULL, 1, 'http://kbforms.local/f/1569a0b41fed', '2026-05-16 05:36:56', '2026-05-16 06:14:18', NULL, NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `form_cache`
--

CREATE TABLE `form_cache` (
  `token` varchar(64) NOT NULL,
  `payload` longtext NOT NULL COMMENT 'JSON du formulaire + questions sérialisé',
  `cached_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `form_cache`
--

INSERT INTO `form_cache` (`token`, `payload`, `cached_at`, `expires_at`) VALUES
('1569a0b41fed', '{\"success\":true,\"form\":{\"id\":30,\"user_id\":12,\"title\":\"Premier formulaire\",\"description\":\"Premier Teste serieux\",\"banner_data\":null,\"is_published\":1,\"share_link\":\"http:\\/\\/kbforms.local\\/f\\/1569a0b41fed\",\"created_at\":\"2026-05-16 06:36:56\",\"updated_at\":\"2026-05-16 07:14:18\",\"share_password_hash\":null,\"confirmation_message\":null,\"theme_color\":null,\"header_image\":null,\"font_family\":null,\"font_size\":null},\"questions\":[{\"id\":158,\"type\":\"short_text\",\"label\":\"Q1\",\"required\":true,\"position\":0,\"image_data\":null,\"section_index\":0,\"options\":[]},{\"id\":159,\"type\":\"long_text\",\"label\":\"Q2\",\"required\":true,\"position\":0,\"image_data\":null,\"section_index\":2,\"options\":[]},{\"id\":160,\"type\":\"dropdown\",\"label\":\"Q3\",\"required\":true,\"position\":0,\"image_data\":null,\"section_index\":1,\"options\":[\"Option 1\",\"Option 2\"]},{\"id\":161,\"type\":\"email\",\"label\":\"email :\",\"required\":true,\"position\":3,\"image_data\":null,\"section_index\":0,\"options\":[]}]}', '2026-05-16 07:19:34', '2026-05-16 07:24:34'),
('1aeb5220555a', '{\"success\":true,\"form\":{\"id\":28,\"user_id\":12,\"title\":\"tesst\",\"description\":\"test\",\"banner_data\":null,\"is_published\":1,\"share_link\":\"http:\\/\\/kbforms.local\\/f\\/1aeb5220555a\",\"created_at\":\"2026-05-11 21:37:21\",\"updated_at\":\"2026-05-12 05:34:07\",\"share_password_hash\":null,\"confirmation_message\":null,\"theme_color\":\"#5d7462\",\"header_image\":null,\"font_family\":\"Roboto\",\"font_size\":19},\"questions\":[{\"id\":131,\"type\":\"grid\",\"label\":\"Question grid\",\"required\":false,\"position\":0,\"image_data\":null,\"section_index\":0,\"options\":[],\"grid_rows\":[],\"grid_columns\":[]},{\"id\":115,\"type\":\"short_text\",\"label\":\"Premier test\",\"required\":true,\"position\":1,\"image_data\":null,\"section_index\":0,\"options\":[]},{\"id\":116,\"type\":\"long_text\",\"label\":\"Deuxieme test\",\"required\":true,\"position\":2,\"image_data\":null,\"section_index\":0,\"options\":[]},{\"id\":125,\"type\":\"radio\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":3,\"image_data\":null,\"section_index\":0,\"options\":[\"Option 1\",\"Option 2\"]},{\"id\":126,\"type\":\"checkbox\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":4,\"image_data\":null,\"section_index\":0,\"options\":[\"Option 1\",\"Option 2\"]},{\"id\":127,\"type\":\"dropdown\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":5,\"image_data\":null,\"section_index\":0,\"options\":[\"Option 1\",\"Option 2\"]},{\"id\":128,\"type\":\"date\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":6,\"image_data\":null,\"section_index\":0,\"options\":[]},{\"id\":129,\"type\":\"time\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":7,\"image_data\":null,\"section_index\":0,\"options\":[]}]}', '2026-05-12 05:34:14', '2026-05-12 05:39:14'),
('2493ee70bc43', '{\"success\":true,\"form\":{\"id\":28,\"user_id\":12,\"title\":\"tesst\",\"description\":\"test\",\"banner_data\":null,\"is_published\":1,\"share_link\":\"http:\\/\\/kbforms.local\\/f\\/2493ee70bc43\",\"created_at\":\"2026-05-11 21:37:21\",\"updated_at\":\"2026-05-12 05:40:58\",\"share_password_hash\":null,\"confirmation_message\":null,\"theme_color\":\"#5d7462\",\"header_image\":null,\"font_family\":\"Roboto\",\"font_size\":19},\"questions\":[{\"id\":131,\"type\":\"grid\",\"label\":\"Question grid\",\"required\":false,\"position\":0,\"image_data\":null,\"section_index\":0,\"options\":[],\"grid_rows\":[\"Ligne 1\",\"Ligne 2\",\"Ligne 3\"],\"grid_columns\":[\"Colonne A\",\"Colonne B\",\"Colonne C\"]},{\"id\":115,\"type\":\"short_text\",\"label\":\"Premier test\",\"required\":true,\"position\":1,\"image_data\":null,\"section_index\":0,\"options\":[]},{\"id\":116,\"type\":\"long_text\",\"label\":\"Deuxieme test\",\"required\":true,\"position\":2,\"image_data\":null,\"section_index\":0,\"options\":[]},{\"id\":125,\"type\":\"radio\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":3,\"image_data\":null,\"section_index\":0,\"options\":[\"Option 1\",\"Option 2\"]},{\"id\":126,\"type\":\"checkbox\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":4,\"image_data\":null,\"section_index\":0,\"options\":[\"Option 1\",\"Option 2\"]},{\"id\":127,\"type\":\"dropdown\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":5,\"image_data\":null,\"section_index\":0,\"options\":[\"Option 1\",\"Option 2\"]},{\"id\":128,\"type\":\"date\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":6,\"image_data\":null,\"section_index\":0,\"options\":[]},{\"id\":129,\"type\":\"time\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":7,\"image_data\":null,\"section_index\":0,\"options\":[]}]}', '2026-05-12 05:41:02', '2026-05-12 05:46:02'),
('4a0ee0ba4210', '{\"success\":true,\"form\":{\"id\":28,\"user_id\":12,\"title\":\"tesst\",\"description\":\"test\",\"banner_data\":null,\"is_published\":1,\"share_link\":\"http:\\/\\/catfight-unreeling-wrist.ngrok-free.dev\\/f\\/4a0ee0ba4210\",\"created_at\":\"2026-05-11 21:37:21\",\"updated_at\":\"2026-05-12 13:35:19\",\"share_password_hash\":null,\"confirmation_message\":null,\"theme_color\":\"#5d7462\",\"header_image\":null,\"font_family\":\"Roboto\",\"font_size\":19},\"questions\":[{\"id\":131,\"type\":\"grid\",\"label\":\"Question grid\",\"required\":false,\"position\":0,\"image_data\":null,\"section_index\":0,\"options\":[],\"grid_rows\":[\"Ligne 1\",\"Ligne 2\",\"Ligne 3\"],\"grid_columns\":[\"Colonne A\",\"Colonne B\",\"Colonne C\"]},{\"id\":115,\"type\":\"short_text\",\"label\":\"Premier test\",\"required\":true,\"position\":1,\"image_data\":null,\"section_index\":0,\"options\":[]},{\"id\":116,\"type\":\"long_text\",\"label\":\"Deuxieme test\",\"required\":true,\"position\":2,\"image_data\":null,\"section_index\":0,\"options\":[]},{\"id\":125,\"type\":\"radio\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":3,\"image_data\":null,\"section_index\":0,\"options\":[\"Option 1\",\"Option 2\"]},{\"id\":126,\"type\":\"checkbox\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":4,\"image_data\":null,\"section_index\":0,\"options\":[\"Option 1\",\"Option 2\"]},{\"id\":127,\"type\":\"dropdown\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":5,\"image_data\":null,\"section_index\":0,\"options\":[\"Option 1\",\"Option 2\"]},{\"id\":128,\"type\":\"date\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":6,\"image_data\":null,\"section_index\":0,\"options\":[]},{\"id\":129,\"type\":\"time\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":7,\"image_data\":null,\"section_index\":0,\"options\":[]}]}', '2026-05-12 13:35:29', '2026-05-12 13:40:29'),
('5d4647db9333', '{\"success\":true,\"form\":{\"id\":28,\"user_id\":12,\"title\":\"tesst\",\"description\":\"test\",\"banner_data\":null,\"is_published\":1,\"share_link\":\"http:\\/\\/kbforms.local\\/f\\/5d4647db9333\",\"created_at\":\"2026-05-11 21:37:21\",\"updated_at\":\"2026-05-15 08:13:27\",\"share_password_hash\":null,\"confirmation_message\":null,\"theme_color\":\"#5d7462\",\"header_image\":null,\"font_family\":\"Roboto\",\"font_size\":19},\"questions\":[{\"id\":131,\"type\":\"grid\",\"label\":\"Question grid\",\"required\":false,\"position\":0,\"image_data\":null,\"section_index\":0,\"options\":[],\"grid_rows\":[\"Ligne 1\",\"Ligne 2\",\"Ligne 3\"],\"grid_columns\":[\"Colonne A\",\"Colonne B\",\"Colonne C\"]},{\"id\":149,\"type\":\"date\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":0,\"image_data\":null,\"section_index\":0,\"options\":[]},{\"id\":151,\"type\":\"date\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":1,\"image_data\":null,\"section_index\":0,\"options\":[]},{\"id\":153,\"type\":\"time\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":2,\"image_data\":null,\"section_index\":0,\"options\":[]},{\"id\":152,\"type\":\"dropdown\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":3,\"image_data\":null,\"section_index\":0,\"options\":[\"Option 1\",\"Option 2\"]},{\"id\":154,\"type\":\"time\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":4,\"image_data\":null,\"section_index\":0,\"options\":[]},{\"id\":155,\"type\":\"phone\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":5,\"image_data\":null,\"section_index\":0,\"options\":[],\"phone_default_country\":\"\"},{\"id\":156,\"type\":\"grid\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":6,\"image_data\":null,\"section_index\":0,\"options\":[],\"grid_rows\":[\"Ligne 1\",\"Ligne 2\"],\"grid_columns\":[\"Colonne A\",\"Colonne B\"]},{\"id\":115,\"type\":\"short_text\",\"label\":\"Premier test\",\"required\":true,\"position\":7,\"image_data\":null,\"section_index\":0,\"options\":[]},{\"id\":116,\"type\":\"long_text\",\"label\":\"Deuxieme test\",\"required\":true,\"position\":8,\"image_data\":null,\"section_index\":0,\"options\":[]},{\"id\":125,\"type\":\"radio\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":9,\"image_data\":null,\"section_index\":0,\"options\":[\"Option 1\",\"Option 2\"]},{\"id\":148,\"type\":\"date\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":10,\"image_data\":null,\"section_index\":0,\"options\":[]},{\"id\":150,\"type\":\"date\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":11,\"image_data\":null,\"section_index\":0,\"options\":[]}]}', '2026-05-15 18:16:45', '2026-05-15 18:21:45'),
('6a5902228238', '{\"success\":true,\"form\":{\"id\":28,\"user_id\":12,\"title\":\"tesst\",\"description\":\"test\",\"banner_data\":null,\"is_published\":1,\"share_link\":\"http:\\/\\/kbforms.local\\/f\\/6a5902228238\",\"created_at\":\"2026-05-11 21:37:21\",\"updated_at\":\"2026-05-12 05:04:27\",\"share_password_hash\":null,\"confirmation_message\":null,\"theme_color\":\"#48e567\",\"header_image\":null,\"font_family\":\"Roboto\",\"font_size\":14},\"questions\":[{\"id\":115,\"type\":\"short_text\",\"label\":\"Premier test\",\"required\":true,\"position\":0,\"image_data\":null,\"section_index\":0,\"options\":[]},{\"id\":116,\"type\":\"long_text\",\"label\":\"Deuxieme test\",\"required\":true,\"position\":1,\"image_data\":null,\"section_index\":0,\"options\":[]}]}', '2026-05-12 05:04:34', '2026-05-12 05:09:34'),
('6a9934e52023', '{\"success\":true,\"form\":{\"id\":28,\"user_id\":12,\"title\":\"tesst\",\"description\":\"test\",\"banner_data\":null,\"is_published\":1,\"share_link\":\"http:\\/\\/kbforms.local\\/f\\/6a9934e52023\",\"created_at\":\"2026-05-11 21:37:21\",\"updated_at\":\"2026-05-11 21:37:55\",\"share_password_hash\":null,\"confirmation_message\":null,\"theme_color\":null,\"header_image\":null,\"font_family\":null,\"font_size\":null},\"questions\":[{\"id\":115,\"type\":\"short_text\",\"label\":\"Premier test\",\"required\":true,\"position\":0,\"image_data\":null,\"section_index\":0,\"options\":[]},{\"id\":116,\"type\":\"long_text\",\"label\":\"Deuxieme test\",\"required\":true,\"position\":1,\"image_data\":null,\"section_index\":0,\"options\":[]}]}', '2026-05-11 21:38:11', '2026-05-11 21:43:11'),
('73ba2536fea8', '{\"success\":true,\"form\":{\"id\":29,\"user_id\":12,\"title\":\"deux\",\"description\":\"tadaaa\",\"banner_data\":null,\"is_published\":1,\"share_link\":\"http:\\/\\/kbforms.local\\/f\\/73ba2536fea8\",\"created_at\":\"2026-05-13 08:07:12\",\"updated_at\":\"2026-05-15 14:13:03\",\"share_password_hash\":null,\"confirmation_message\":null,\"theme_color\":\"#0b084a\",\"header_image\":null,\"font_family\":\"Plus Jakarta Sans\",\"font_size\":17},\"questions\":[{\"id\":144,\"type\":\"linear_scale\",\"label\":\"Niveau\",\"required\":false,\"position\":0,\"image_data\":null,\"section_index\":0,\"options\":[],\"scale_min\":1,\"scale_max\":5,\"scale_step\":1},{\"id\":145,\"type\":\"phone\",\"label\":\"Tel:\",\"required\":true,\"position\":1,\"image_data\":null,\"section_index\":0,\"options\":[],\"phone_default_country\":\"\"},{\"id\":146,\"type\":\"grid\",\"label\":\"Quel model:\",\"required\":true,\"position\":2,\"image_data\":null,\"section_index\":0,\"options\":[],\"grid_rows\":[\"CPU\",\"RAM\"],\"grid_columns\":[\"CPU\",\"RAM\"]}]}', '2026-05-15 18:11:47', '2026-05-15 18:16:47'),
('74a67291194a', '{\"success\":true,\"form\":{\"id\":30,\"user_id\":12,\"title\":\"Premier formulaire\",\"description\":\"Premier Teste serieux\",\"banner_data\":null,\"is_published\":1,\"share_link\":\"http:\\/\\/kbforms.local\\/f\\/74a67291194a\",\"created_at\":\"2026-05-16 06:36:56\",\"updated_at\":\"2026-05-16 07:13:33\",\"share_password_hash\":null,\"confirmation_message\":null,\"theme_color\":null,\"header_image\":null,\"font_family\":null,\"font_size\":null},\"questions\":[{\"id\":158,\"type\":\"short_text\",\"label\":\"Q1\",\"required\":true,\"position\":0,\"image_data\":null,\"section_index\":0,\"options\":[]},{\"id\":159,\"type\":\"long_text\",\"label\":\"Q2\",\"required\":false,\"position\":0,\"image_data\":null,\"section_index\":2,\"options\":[]},{\"id\":160,\"type\":\"dropdown\",\"label\":\"Q3\",\"required\":true,\"position\":0,\"image_data\":null,\"section_index\":1,\"options\":[\"Option 1\",\"Option 2\"]},{\"id\":161,\"type\":\"email\",\"label\":\"email :\",\"required\":false,\"position\":3,\"image_data\":null,\"section_index\":0,\"options\":[]}]}', '2026-05-16 07:13:39', '2026-05-16 07:18:39'),
('922a47b74ac2', '{\"success\":true,\"form\":{\"id\":28,\"user_id\":12,\"title\":\"tesst\",\"description\":\"test\",\"banner_data\":null,\"is_published\":1,\"share_link\":\"http:\\/\\/kbforms.local\\/f\\/922a47b74ac2\",\"created_at\":\"2026-05-11 21:37:21\",\"updated_at\":\"2026-05-12 05:27:30\",\"share_password_hash\":null,\"confirmation_message\":null,\"theme_color\":\"#48e567\",\"header_image\":null,\"font_family\":\"Roboto\",\"font_size\":14},\"questions\":[{\"id\":115,\"type\":\"short_text\",\"label\":\"Premier test\",\"required\":true,\"position\":0,\"image_data\":null,\"section_index\":0,\"options\":[]},{\"id\":116,\"type\":\"long_text\",\"label\":\"Deuxieme test\",\"required\":true,\"position\":1,\"image_data\":null,\"section_index\":0,\"options\":[]},{\"id\":125,\"type\":\"radio\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":2,\"image_data\":null,\"section_index\":0,\"options\":[\"Option 1\",\"Option 2\"]},{\"id\":126,\"type\":\"checkbox\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":3,\"image_data\":null,\"section_index\":0,\"options\":[\"Option 1\",\"Option 2\"]},{\"id\":127,\"type\":\"dropdown\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":4,\"image_data\":null,\"section_index\":0,\"options\":[\"Option 1\",\"Option 2\"]},{\"id\":128,\"type\":\"date\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":5,\"image_data\":null,\"section_index\":0,\"options\":[]},{\"id\":129,\"type\":\"time\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":6,\"image_data\":null,\"section_index\":0,\"options\":[]},{\"id\":130,\"type\":\"linear_scale\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":7,\"image_data\":null,\"section_index\":0,\"options\":[],\"scale_min\":1,\"scale_max\":5,\"scale_step\":1},{\"id\":131,\"type\":\"grid\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":8,\"image_data\":null,\"section_index\":0,\"options\":[],\"grid_rows\":[],\"grid_columns\":[]}]}', '2026-05-12 05:27:51', '2026-05-12 05:32:51'),
('924b38c726a3', '{\"success\":true,\"form\":{\"id\":28,\"user_id\":12,\"title\":\"tesst\",\"description\":\"test\",\"banner_data\":null,\"is_published\":1,\"share_link\":\"http:\\/\\/kbforms.local\\/f\\/924b38c726a3\",\"created_at\":\"2026-05-11 21:37:21\",\"updated_at\":\"2026-05-12 05:41:44\",\"share_password_hash\":null,\"confirmation_message\":null,\"theme_color\":\"#5d7462\",\"header_image\":null,\"font_family\":\"Roboto\",\"font_size\":19},\"questions\":[{\"id\":131,\"type\":\"grid\",\"label\":\"Question grid\",\"required\":false,\"position\":0,\"image_data\":null,\"section_index\":0,\"options\":[],\"grid_rows\":[\"Ligne 1\",\"Ligne 2\",\"Ligne 3\"],\"grid_columns\":[\"Colonne A\",\"Colonne B\",\"Colonne C\"]},{\"id\":115,\"type\":\"short_text\",\"label\":\"Premier test\",\"required\":true,\"position\":1,\"image_data\":null,\"section_index\":0,\"options\":[]},{\"id\":116,\"type\":\"long_text\",\"label\":\"Deuxieme test\",\"required\":true,\"position\":2,\"image_data\":null,\"section_index\":0,\"options\":[]},{\"id\":125,\"type\":\"radio\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":3,\"image_data\":null,\"section_index\":0,\"options\":[\"Option 1\",\"Option 2\"]},{\"id\":126,\"type\":\"checkbox\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":4,\"image_data\":null,\"section_index\":0,\"options\":[\"Option 1\",\"Option 2\"]},{\"id\":127,\"type\":\"dropdown\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":5,\"image_data\":null,\"section_index\":0,\"options\":[\"Option 1\",\"Option 2\"]},{\"id\":128,\"type\":\"date\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":6,\"image_data\":null,\"section_index\":0,\"options\":[]},{\"id\":129,\"type\":\"time\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":7,\"image_data\":null,\"section_index\":0,\"options\":[]}]}', '2026-05-12 05:41:50', '2026-05-12 05:46:50'),
('95fedb5bbe03', '{\"success\":true,\"form\":{\"id\":28,\"user_id\":12,\"title\":\"tesst\",\"description\":\"test\",\"banner_data\":null,\"is_published\":1,\"share_link\":\"http:\\/\\/kbforms.local\\/f\\/95fedb5bbe03\",\"created_at\":\"2026-05-11 21:37:21\",\"updated_at\":\"2026-05-12 06:42:40\",\"share_password_hash\":null,\"confirmation_message\":null,\"theme_color\":\"#5d7462\",\"header_image\":null,\"font_family\":\"Roboto\",\"font_size\":19},\"questions\":[{\"id\":131,\"type\":\"grid\",\"label\":\"Question grid\",\"required\":false,\"position\":0,\"image_data\":null,\"section_index\":0,\"options\":[],\"grid_rows\":[\"Ligne 1\",\"Ligne 2\",\"Ligne 3\"],\"grid_columns\":[\"Colonne A\",\"Colonne B\",\"Colonne C\"]},{\"id\":115,\"type\":\"short_text\",\"label\":\"Premier test\",\"required\":true,\"position\":1,\"image_data\":null,\"section_index\":0,\"options\":[]},{\"id\":116,\"type\":\"long_text\",\"label\":\"Deuxieme test\",\"required\":true,\"position\":2,\"image_data\":null,\"section_index\":0,\"options\":[]},{\"id\":125,\"type\":\"radio\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":3,\"image_data\":null,\"section_index\":0,\"options\":[\"Option 1\",\"Option 2\"]},{\"id\":126,\"type\":\"checkbox\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":4,\"image_data\":null,\"section_index\":0,\"options\":[\"Option 1\",\"Option 2\"]},{\"id\":127,\"type\":\"dropdown\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":5,\"image_data\":null,\"section_index\":0,\"options\":[\"Option 1\",\"Option 2\"]},{\"id\":128,\"type\":\"date\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":6,\"image_data\":null,\"section_index\":0,\"options\":[]},{\"id\":129,\"type\":\"time\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":7,\"image_data\":null,\"section_index\":0,\"options\":[]}]}', '2026-05-12 13:32:21', '2026-05-12 13:37:21'),
('b963e0d27a9f', '{\"success\":true,\"form\":{\"id\":27,\"user_id\":12,\"title\":\"fhgjhfhgjk\",\"description\":\"hgjhj\",\"banner_data\":null,\"is_published\":1,\"share_link\":\"http:\\/\\/kbforms.local\\/f\\/b963e0d27a9f\",\"created_at\":\"2026-05-11 21:27:59\",\"updated_at\":\"2026-05-11 21:28:20\",\"share_password_hash\":null,\"confirmation_message\":null,\"theme_color\":null,\"header_image\":null,\"font_family\":null,\"font_size\":null},\"questions\":[{\"id\":113,\"type\":\"short_text\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":0,\"image_data\":null,\"section_index\":0,\"options\":[]},{\"id\":114,\"type\":\"long_text\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":1,\"image_data\":null,\"section_index\":0,\"options\":[]}]}', '2026-05-11 21:28:26', '2026-05-11 21:33:26'),
('bd80d78279e1', '{\"success\":true,\"form\":{\"id\":28,\"user_id\":12,\"title\":\"tesst\",\"description\":\"test\",\"banner_data\":null,\"is_published\":1,\"share_link\":\"http:\\/\\/kbforms.local\\/f\\/bd80d78279e1\",\"created_at\":\"2026-05-11 21:37:21\",\"updated_at\":\"2026-05-11 21:42:59\",\"share_password_hash\":null,\"confirmation_message\":null,\"theme_color\":null,\"header_image\":null,\"font_family\":null,\"font_size\":null},\"questions\":[{\"id\":115,\"type\":\"short_text\",\"label\":\"Premier test\",\"required\":true,\"position\":0,\"image_data\":null,\"section_index\":0,\"options\":[]},{\"id\":116,\"type\":\"long_text\",\"label\":\"Deuxieme test\",\"required\":true,\"position\":1,\"image_data\":null,\"section_index\":0,\"options\":[]}]}', '2026-05-11 21:43:04', '2026-05-11 21:48:04'),
('cb3180c2767f', '{\"success\":true,\"form\":{\"id\":28,\"user_id\":12,\"title\":\"tesst\",\"description\":\"test\",\"banner_data\":null,\"is_published\":1,\"share_link\":\"http:\\/\\/kbforms.local\\/f\\/cb3180c2767f\",\"created_at\":\"2026-05-11 21:37:21\",\"updated_at\":\"2026-05-12 05:28:13\",\"share_password_hash\":null,\"confirmation_message\":null,\"theme_color\":\"#5d7462\",\"header_image\":null,\"font_family\":\"Roboto\",\"font_size\":19},\"questions\":[{\"id\":115,\"type\":\"short_text\",\"label\":\"Premier test\",\"required\":true,\"position\":0,\"image_data\":null,\"section_index\":0,\"options\":[]},{\"id\":116,\"type\":\"long_text\",\"label\":\"Deuxieme test\",\"required\":true,\"position\":1,\"image_data\":null,\"section_index\":0,\"options\":[]},{\"id\":125,\"type\":\"radio\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":2,\"image_data\":null,\"section_index\":0,\"options\":[\"Option 1\",\"Option 2\"]},{\"id\":126,\"type\":\"checkbox\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":3,\"image_data\":null,\"section_index\":0,\"options\":[\"Option 1\",\"Option 2\"]},{\"id\":127,\"type\":\"dropdown\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":4,\"image_data\":null,\"section_index\":0,\"options\":[\"Option 1\",\"Option 2\"]},{\"id\":128,\"type\":\"date\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":5,\"image_data\":null,\"section_index\":0,\"options\":[]},{\"id\":129,\"type\":\"time\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":6,\"image_data\":null,\"section_index\":0,\"options\":[]},{\"id\":130,\"type\":\"linear_scale\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":7,\"image_data\":null,\"section_index\":0,\"options\":[],\"scale_min\":1,\"scale_max\":5,\"scale_step\":1},{\"id\":131,\"type\":\"grid\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":8,\"image_data\":null,\"section_index\":0,\"options\":[],\"grid_rows\":[],\"grid_columns\":[]}]}', '2026-05-12 05:28:19', '2026-05-12 05:33:19'),
('d59ef7f182dd', '{\"success\":true,\"form\":{\"id\":26,\"user_id\":12,\"title\":\"fe\",\"description\":\"e\",\"banner_data\":null,\"is_published\":1,\"share_link\":\"http:\\/\\/kbforms.local\\/f\\/d59ef7f182dd\",\"created_at\":\"2026-05-11 21:16:02\",\"updated_at\":\"2026-05-11 21:16:10\",\"share_password_hash\":null,\"confirmation_message\":null,\"theme_color\":null,\"header_image\":null,\"font_family\":null,\"font_size\":null},\"questions\":[{\"id\":111,\"type\":\"short_text\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":0,\"image_data\":null,\"section_index\":0,\"options\":[]},{\"id\":112,\"type\":\"long_text\",\"label\":\"Nouvelle question\",\"required\":false,\"position\":1,\"image_data\":null,\"section_index\":0,\"options\":[]}]}', '2026-05-11 21:26:30', '2026-05-11 21:31:30'),
('f5e702c11483', '{\"success\":true,\"form\":{\"id\":28,\"user_id\":12,\"title\":\"tesst\",\"description\":\"test\",\"banner_data\":null,\"is_published\":1,\"share_link\":\"http:\\/\\/kbforms.local\\/f\\/f5e702c11483\",\"created_at\":\"2026-05-11 21:37:21\",\"updated_at\":\"2026-05-12 04:47:19\",\"share_password_hash\":null,\"confirmation_message\":null,\"theme_color\":\"#e5cb48\",\"header_image\":null,\"font_family\":\"Plus Jakarta Sans\",\"font_size\":17},\"questions\":[{\"id\":115,\"type\":\"short_text\",\"label\":\"Premier test\",\"required\":true,\"position\":0,\"image_data\":null,\"section_index\":0,\"options\":[]},{\"id\":116,\"type\":\"long_text\",\"label\":\"Deuxieme test\",\"required\":true,\"position\":1,\"image_data\":null,\"section_index\":0,\"options\":[]}]}', '2026-05-12 04:55:04', '2026-05-12 05:00:04');

-- --------------------------------------------------------

--
-- Table structure for table `form_collaborators`
--

CREATE TABLE `form_collaborators` (
  `id` int(10) UNSIGNED NOT NULL,
  `form_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `role` enum('viewer','editor','admin') NOT NULL DEFAULT 'viewer' COMMENT 'viewer=lecture, editor=questions, admin=publish+settings',
  `invited_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `form_invitations`
-- Invitations de collaboration en attente (adresse email pas encore acceptée).
--

CREATE TABLE `form_invitations` (
  `id` int(11) NOT NULL,
  `form_id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `role` enum('viewer','editor','admin') NOT NULL DEFAULT 'viewer',
  `token` char(40) NOT NULL,
  `invited_by` int(11) DEFAULT NULL,
  `status` enum('pending','accepted','revoked') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `accepted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_token` (`token`),
  UNIQUE KEY `uniq_form_email` (`form_id`,`email`),
  KEY `idx_form` (`form_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `form_assignments`
-- M2 · B2 (mobile) — un superviseur confie la collecte d'un formulaire à un
-- enquêteur. Distinct de form_collaborators (droits) : ici c'est une tâche.
-- migrations/2026-09-03_mobile_form_assignments.sql
--

CREATE TABLE `form_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `form_id` int(11) NOT NULL,
  `assigned_to_user_id` int(11) NOT NULL,
  `assigned_by_user_id` int(11) NOT NULL,
  `status` enum('pending','in_progress','done') NOT NULL DEFAULT 'pending',
  `note` text DEFAULT NULL COMMENT 'consigne libre du superviseur (périmètre, quota…)',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_form_assignee` (`form_id`,`assigned_to_user_id`),
  KEY `idx_assignment_assignee` (`assigned_to_user_id`,`status`),
  KEY `idx_assignment_form` (`form_id`),
  CONSTRAINT `fk_assignment_form` FOREIGN KEY (`form_id`) REFERENCES `forms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_assignment_assignee` FOREIGN KEY (`assigned_to_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_assignment_assigner` FOREIGN KEY (`assigned_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `device_tokens`
-- M2 · B3 (mobile) — jetons d'enregistrement Firebase Cloud Messaging.
-- migrations/2026-09-03_mobile_device_tokens.sql
--

CREATE TABLE `device_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token` varchar(512) NOT NULL COMMENT 'jeton d''enregistrement FCM',
  `platform` enum('android','ios') NOT NULL DEFAULT 'android',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_seen_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_device_token` (`token`),
  KEY `idx_device_user` (`user_id`),
  CONSTRAINT `fk_device_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `form_agents`
-- M4 (mobile) — agents de terrain scopés à une enquête (identifiant + mot de
-- passe générés par le système, valables tant que le formulaire est publié).
-- migrations/2026-09-04_mobile_form_agents.sql
--

CREATE TABLE `form_agents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `form_id` int(11) NOT NULL,
  `identifiant` varchar(32) NOT NULL COMMENT 'unique globalement — la connexion agent ne précise pas le formulaire',
  `password_hash` varchar(255) NOT NULL,
  `display_name` varchar(255) NOT NULL COMMENT 'nom donné par l''entreprise pour reconnaître l''agent (ex. "Jean Dupont")',
  `email` varchar(255) NOT NULL DEFAULT '' COMMENT 'migrations/2026-09-05_agent_email.sql — envoi automatique des identifiants',
  `is_revoked` tinyint(1) NOT NULL DEFAULT 0,
  `created_by_user_id` int(11) NOT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_form_agents_identifiant` (`identifiant`),
  KEY `idx_form_agents_form` (`form_id`),
  CONSTRAINT `fk_form_agents_form` FOREIGN KEY (`form_id`) REFERENCES `forms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_form_agents_creator` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `form_google_sheets`
--

CREATE TABLE `form_google_sheets` (
  `id` int(11) NOT NULL,
  `form_id` int(11) NOT NULL,
  `spreadsheet_id` varchar(255) NOT NULL,
  `spreadsheet_title` varchar(255) DEFAULT NULL,
  `sheet_name` varchar(255) DEFAULT 'Réponses',
  `access_token` text DEFAULT NULL,
  `refresh_token` text DEFAULT NULL,
  `token_expires_at` datetime DEFAULT NULL,
  `auto_sync` tinyint(1) NOT NULL DEFAULT 0,
  `last_synced_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `options`
--

CREATE TABLE `options` (
  `id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `value` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `options`
--

INSERT INTO `options` (`id`, `question_id`, `value`) VALUES
(19, 26, 'Entrepreneur'),
(20, 26, 'Free-lance'),
(21, 26, 'Etudiant'),
(22, 26, 'Travailleur'),
(23, 26, 'Autre :'),
(24, 27, 'Booster ma carrière'),
(25, 27, 'Trouver un emploi'),
(26, 27, 'Améliorer mes compétences'),
(27, 27, 'Mieux récruter'),
(28, 28, 'Oui'),
(29, 28, 'Non'),
(40, 105, 'Option 1'),
(41, 105, 'Option 2'),
(46, 125, 'Option 1'),
(47, 125, 'Option 2'),
(58, 152, 'Option 1'),
(59, 152, 'Option 2'),
(84, 160, 'Option 1'),
(85, 160, 'Option 2');

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

CREATE TABLE `permissions` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `permissions`
--

INSERT INTO `permissions` (`id`, `name`) VALUES
(11, 'analytics.view'),
(3, 'forms.create'),
(5, 'forms.delete'),
(4, 'forms.edit'),
(6, 'forms.publish'),
(8, 'responses.delete'),
(9, 'responses.export'),
(7, 'responses.view'),
(10, 'roles.manage'),
(12, 'settings.manage'),
(1, 'users.manage'),
(2, 'users.view');

-- --------------------------------------------------------

--
-- Table structure for table `questions`
--

CREATE TABLE `questions` (
  `id` int(11) NOT NULL,
  `form_id` int(11) NOT NULL,
  `type` enum('short_text','long_text','radio','checkbox','dropdown','date','time','linear_scale','grid','phone','email','signature','audio','video','calculated') NOT NULL,
  `label` varchar(255) NOT NULL,
  `help_text` varchar(500) DEFAULT NULL,
  `required` tinyint(1) DEFAULT 0,
  `position` int(11) DEFAULT 0,
  `image_data` mediumtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `section_index` int(11) DEFAULT 0,
  `scale_min` int(11) DEFAULT NULL COMMENT 'linear_scale : valeur minimale',
  `scale_max` int(11) DEFAULT NULL COMMENT 'linear_scale : valeur maximale',
  `scale_step` int(11) DEFAULT NULL COMMENT 'linear_scale : pas entre deux valeurs',
  `grid_rows` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'grid : tableau des libellés de lignes' CHECK (json_valid(`grid_rows`)),
  `grid_columns` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'grid : tableau des libellés de colonnes' CHECK (json_valid(`grid_columns`)),
  `phone_default_country` varchar(3) DEFAULT NULL COMMENT 'Code ISO-3166 du pays par défaut (ex: CM, FR, US)',
  `cascade_list_id` int(11) DEFAULT NULL COMMENT 'B7 : liste de choix hiérarchique liée (dropdown/radio)',
  `cascade_parent_question_id` int(11) DEFAULT NULL COMMENT 'B7 : question fournissant le filtre parent (NULL = racine)',
  `media_max_duration_s` int(11) DEFAULT NULL COMMENT 'B5 : audio/video, durée max conseillée côté client (s)',
  `allow_photo` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'M5 : autorise la jointure de photos sur cette question au remplissage (opt-in) — migrations/2026-09-07_question_allow_photo.sql',
  `calculated_expression` text DEFAULT NULL COMMENT 'B6 : type calculated, formule ({q12} + {q13}, age({q4})…)',
  `repeat_group_id` int(11) DEFAULT NULL COMMENT 'B8 : la question appartient à ce groupe répétable (FK repeat_groups)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `questions`
--

INSERT INTO `questions` (`id`, `form_id`, `type`, `label`, `required`, `position`, `image_data`, `created_at`, `section_index`, `scale_min`, `scale_max`, `scale_step`, `grid_rows`, `grid_columns`, `phone_default_country`) VALUES
(21, 5, 'short_text', 'NOM', 1, 0, NULL, '2026-04-28 11:54:06', 0, NULL, NULL, NULL, NULL, NULL, NULL),
(22, 5, 'short_text', 'PRENOM', 1, 1, NULL, '2026-04-28 11:54:06', 0, NULL, NULL, NULL, NULL, NULL, NULL),
(23, 5, 'short_text', 'numero de télephone/whatsapp ', 1, 2, NULL, '2026-04-28 11:54:06', 0, NULL, NULL, NULL, NULL, NULL, NULL),
(24, 5, 'short_text', 'Email', 1, 3, NULL, '2026-04-28 11:54:07', 0, NULL, NULL, NULL, NULL, NULL, NULL),
(25, 5, 'short_text', 'Ville', 1, 4, NULL, '2026-04-28 11:54:07', 0, NULL, NULL, NULL, NULL, NULL, NULL),
(26, 5, 'radio', 'Profession', 1, 5, NULL, '2026-04-28 11:54:07', 0, NULL, NULL, NULL, NULL, NULL, NULL),
(27, 5, 'checkbox', 'Pourquoi souhaitez vous participer à cette MasterClass?', 1, 6, NULL, '2026-04-28 11:54:07', 0, NULL, NULL, NULL, NULL, NULL, NULL),
(28, 5, 'radio', 'Avez vous déjà une certification?', 1, 7, NULL, '2026-04-28 11:54:08', 0, NULL, NULL, NULL, NULL, NULL, NULL),
(82, 20, 'long_text', 'Nouvelle question', 0, 4, NULL, '2026-05-10 17:31:12', 0, NULL, NULL, NULL, NULL, NULL, NULL),
(83, 20, 'short_text', 'Nouvelle question', 0, 5, NULL, '2026-05-10 17:31:12', 0, NULL, NULL, NULL, NULL, NULL, NULL),
(103, 23, 'short_text', 'Nouvelle ', 0, 1, NULL, '2026-05-10 21:30:13', 0, NULL, NULL, NULL, NULL, NULL, NULL),
(104, 23, 'long_text', 'Nouvelle question', 0, 0, NULL, '2026-05-10 21:30:27', 0, NULL, NULL, NULL, NULL, NULL, NULL),
(105, 23, 'dropdown', 'Nouvelle question', 0, 2, NULL, '2026-05-10 21:30:41', 0, NULL, NULL, NULL, NULL, NULL, NULL),
(106, 23, 'date', 'Nouvelle question', 0, 3, NULL, '2026-05-10 21:30:48', 0, NULL, NULL, NULL, NULL, NULL, NULL),
(115, 28, 'short_text', 'Premier test', 1, 7, NULL, '2026-05-11 20:37:23', 0, NULL, NULL, NULL, NULL, NULL, NULL),
(116, 28, 'long_text', 'Deuxieme test', 1, 8, NULL, '2026-05-11 20:37:38', 0, NULL, NULL, NULL, NULL, NULL, NULL),
(125, 28, 'radio', 'Nouvelle question', 0, 9, NULL, '2026-05-12 04:26:34', 0, NULL, NULL, NULL, NULL, NULL, NULL),
(131, 28, 'grid', 'Question grid', 0, 0, NULL, '2026-05-12 04:27:01', 0, NULL, NULL, NULL, '[\"Ligne 1\",\"Ligne 2\",\"Ligne 3\"]', '[\"Colonne A\",\"Colonne B\",\"Colonne C\"]', NULL),
(144, 29, 'linear_scale', 'Niveau', 0, 0, NULL, '2026-05-13 07:07:22', 0, 1, 5, 1, NULL, NULL, NULL),
(145, 29, 'phone', 'Tel:', 1, 1, NULL, '2026-05-13 07:07:27', 0, NULL, NULL, NULL, NULL, NULL, ''),
(146, 29, 'grid', 'Quel model:', 1, 2, NULL, '2026-05-13 07:07:59', 0, NULL, NULL, NULL, '[\"CPU\",\"RAM\"]', '[\"CPU\",\"RAM\"]', NULL),
(148, 28, 'date', 'Nouvelle question', 0, 10, NULL, '2026-05-15 07:09:17', 0, NULL, NULL, NULL, NULL, NULL, NULL),
(149, 28, 'date', 'Nouvelle question', 0, 0, NULL, '2026-05-15 07:09:17', 0, NULL, NULL, NULL, NULL, NULL, NULL),
(150, 28, 'date', 'Nouvelle question', 0, 11, NULL, '2026-05-15 07:09:17', 0, NULL, NULL, NULL, NULL, NULL, NULL),
(151, 28, 'date', 'Nouvelle question', 0, 1, NULL, '2026-05-15 07:09:17', 0, NULL, NULL, NULL, NULL, NULL, NULL),
(152, 28, 'dropdown', 'Nouvelle question', 0, 3, NULL, '2026-05-15 07:09:19', 0, NULL, NULL, NULL, NULL, NULL, NULL),
(153, 28, 'time', 'Nouvelle question', 0, 2, NULL, '2026-05-15 07:09:20', 0, NULL, NULL, NULL, NULL, NULL, NULL),
(154, 28, 'time', 'Nouvelle question', 0, 4, NULL, '2026-05-15 07:09:22', 0, NULL, NULL, NULL, NULL, NULL, NULL),
(155, 28, 'phone', 'Nouvelle question', 0, 5, NULL, '2026-05-15 07:09:22', 0, NULL, NULL, NULL, NULL, NULL, ''),
(156, 28, 'grid', 'Nouvelle question', 0, 6, NULL, '2026-05-15 07:09:23', 0, NULL, NULL, NULL, '[\"Ligne 1\",\"Ligne 2\"]', '[\"Colonne A\",\"Colonne B\"]', NULL),
(158, 30, 'short_text', 'Q1', 1, 0, NULL, '2026-05-16 05:50:18', 0, NULL, NULL, NULL, NULL, NULL, NULL),
(159, 30, 'long_text', 'Q2', 1, 0, NULL, '2026-05-16 05:50:46', 2, NULL, NULL, NULL, NULL, NULL, NULL),
(160, 30, 'dropdown', 'Q3', 1, 0, NULL, '2026-05-16 05:51:37', 1, NULL, NULL, NULL, NULL, NULL, NULL),
(161, 30, 'email', 'email :', 1, 3, NULL, '2026-05-16 06:12:44', 0, NULL, NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `choice_lists` / `choice_list_items`
-- M2 · B7 (mobile) — listes de choix en cascade (Région → Département → …).
-- migrations/2026-09-03_mobile_cascade_lists.sql
--

CREATE TABLE `choice_lists` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `form_id` int(11) NOT NULL,
  `name` varchar(120) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_choice_list_form` (`form_id`),
  CONSTRAINT `fk_choice_list_form` FOREIGN KEY (`form_id`) REFERENCES `forms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `choice_list_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `list_id` int(11) NOT NULL,
  `parent_item_id` int(11) DEFAULT NULL COMMENT 'NULL = niveau racine',
  `label` varchar(255) NOT NULL,
  `value` varchar(255) NOT NULL,
  `position` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_cli_list` (`list_id`),
  KEY `idx_cli_parent` (`parent_item_id`),
  CONSTRAINT `fk_cli_list` FOREIGN KEY (`list_id`) REFERENCES `choice_lists` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cli_parent` FOREIGN KEY (`parent_item_id`) REFERENCES `choice_list_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `repeat_groups`
-- M2 · B8 (mobile) — bloc de questions répété N fois (ménage → membres…).
-- questions.repeat_group_id y fait référence (FK ajoutée en fin de fichier).
-- migrations/2026-09-04_mobile_repeat_groups.sql
--

CREATE TABLE `repeat_groups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `form_id` int(11) NOT NULL,
  `section_index` int(11) NOT NULL DEFAULT 0 COMMENT 'section (position ordinale) qui contient le bloc',
  `label` varchar(160) NOT NULL,
  `min_repeat` int(11) NOT NULL DEFAULT 0,
  `max_repeat` int(11) DEFAULT NULL COMMENT 'NULL = pas de plafond',
  `position` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_repeat_group_form` (`form_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `responses`
--

CREATE TABLE `responses` (
  `id` int(11) NOT NULL,
  `client_uuid` char(36) DEFAULT NULL COMMENT 'UUID v4 généré sur l''appareil (idempotence des envois) — cf. migration mobile_response_collection',
  `form_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `agent_id` int(11) DEFAULT NULL COMMENT 'M4 : réponse collectée par un agent de terrain (exclusif de user_id en pratique)',
  `ip_hash` varchar(64) DEFAULT NULL COMMENT 'SHA-256 salé de l''IP (anti-spam / rate-limit, jamais l''IP en clair)',
  `device_id` varchar(64) DEFAULT NULL,
  `app_version` varchar(20) DEFAULT NULL,
  `gps_lat` decimal(10,7) DEFAULT NULL,
  `gps_lng` decimal(10,7) DEFAULT NULL,
  `gps_accuracy` float DEFAULT NULL COMMENT 'Précision GPS en mètres',
  `started_at` datetime DEFAULT NULL COMMENT 'Ouverture de la réponse (horloge appareil)',
  `duration_s` int(11) DEFAULT NULL,
  `mock_location` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Position simulée détectée (non bloquant en M1)',
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  UNIQUE KEY `uq_responses_client_uuid` (`client_uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `responses`
--

INSERT INTO `responses` (`id`, `form_id`, `user_id`, `submitted_at`) VALUES
(3, 5, NULL, '2026-04-28 11:57:40'),
(4, 5, NULL, '2026-04-28 11:58:20'),
(5, 5, NULL, '2026-04-28 11:59:03'),
(6, 5, NULL, '2026-04-28 12:00:25'),
(17, 20, NULL, '2026-05-05 14:59:34'),
(18, 28, NULL, '2026-05-11 20:46:27'),
(19, 28, NULL, '2026-05-12 03:58:28'),
(20, 28, NULL, '2026-05-12 04:29:41'),
(21, 28, NULL, '2026-05-12 04:29:54'),
(22, 28, NULL, '2026-05-12 04:32:43'),
(23, 29, NULL, '2026-05-13 07:10:58'),
(24, 30, NULL, '2026-05-16 06:14:36'),
(25, 30, NULL, '2026-05-16 06:16:00');

-- --------------------------------------------------------

--
-- Table structure for table `response_media`
-- (prérequis M1.5 mobile — cf. migrations/2026-09-02_mobile_response_media.sql)
--

CREATE TABLE `response_media` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `response_id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `mime` varchar(50) DEFAULT NULL,
  `sha256` char(64) DEFAULT NULL COMMENT 'empreinte calculée côté client, pour vérifier l''intégrité',
  `size_bytes` int(11) DEFAULT NULL COMMENT 'taille décodée, informatif',
  `data` mediumtext NOT NULL COMMENT 'data URI base64 (data:image/...;base64,....)',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_response_media_response` (`response_id`),
  KEY `idx_response_media_question` (`question_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `code_hash` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `attempts` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `used` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `refresh_tokens`
-- (prérequis M1 mobile — cf. migrations/2026-09-02_mobile_refresh_tokens.sql)
--

CREATE TABLE `refresh_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token_hash` char(64) NOT NULL COMMENT 'SHA-256 hex du jeton opaque',
  `expires_at` datetime NOT NULL,
  `revoked` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_refresh_token_hash` (`token_hash`),
  KEY `idx_refresh_user` (`user_id`),
  KEY `idx_refresh_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `name`) VALUES
(4, 'admin'),
(5, 'editor'),
(7, 'viewer');

-- --------------------------------------------------------

--
-- Table structure for table `role_permissions`
--

CREATE TABLE `role_permissions` (
  `role_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `role_permissions`
--

INSERT INTO `role_permissions` (`role_id`, `permission_id`) VALUES
(4, 1),
(4, 2),
(4, 3),
(4, 4),
(4, 5),
(4, 6),
(4, 7),
(4, 8),
(4, 9),
(4, 10),
(4, 11),
(4, 12),
(5, 3),
(5, 4),
(5, 6),
(5, 7),
(5, 9),
(5, 11),
(7, 7),
(7, 11);

-- --------------------------------------------------------

--
-- Table structure for table `sections`
--

CREATE TABLE `sections` (
  `id` int(11) NOT NULL,
  `form_id` int(11) NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `position` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `sections`
--

INSERT INTO `sections` (`id`, `form_id`, `title`, `description`, `position`) VALUES
(1, 23, 'yyuu', NULL, 0),
(2, 28, 'Tete ection', NULL, 0),
(3, 28, 'tete2 ection', NULL, 1),
(4, 28, 'gg', NULL, 2),
(5, 29, 'tet', NULL, 0),
(6, 29, 'section2', NULL, 1),
(7, 29, 'setcion3', NULL, 2),
(8, 30, 'section 1', NULL, 0),
(11, 30, 'section 3', NULL, 1),
(12, 30, 'gggg', NULL, 2);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `account_type` enum('enterprise','individual','admin') NOT NULL,
  `organization` varchar(255) DEFAULT NULL,
  `industry` varchar(100) DEFAULT NULL,
  `company_size` varchar(20) DEFAULT NULL,
  `country` varchar(100) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `job_title` varchar(100) DEFAULT NULL,
  `avatar_data` mediumtext DEFAULT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `first_name`, `last_name`, `email`, `password`, `account_type`, `organization`, `industry`, `company_size`, `country`, `phone`, `website`, `job_title`, `created_at`) VALUES
(2, 'admin', 'test', 'admin@test.com', '$2y$10$OZU3rs/KoJ5ybU.WcTN4zuY0LBeh9YD.mlxYRpXAZON4QJ.jIUPMW', 'admin', 'KBForms', NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-24 17:34:03'),
(7, 'admin', 'test', 'admin2@test.com', '$2y$10$MfZFA8KKxg4KmMORYFISB.5CRx.551MGk2fDmB5123kzVA7DHpPRu', 'admin', 'KBForms', NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-26 18:10:51'),
(9, 'Line Audrey', 'NDJASSI', 'line.ndjassi@kbgroup-trainingcenter.com', '$2y$10$JryDuWFkgF8X4cIRTsWGkOjPq8C3X5/JOZTEoh03rlbFCFtceta9K', 'enterprise', 'K&B Group', NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-28 11:22:51'),
(12, 'Super', 'Admin', 'heil.tchamba@kbgroup-trainingcenter.com', '$2y$10$pl6w3FTaq482068V2JeOa.7fjnRkOiYEW6xYw1NDJ8IZ350Hokw0e', 'admin', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-05-11 20:06:56');

-- --------------------------------------------------------

--
-- Table structure for table `user_roles`
--

CREATE TABLE `user_roles` (
  `user_id` int(11) NOT NULL,
  `role_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `user_roles`
--

INSERT INTO `user_roles` (`user_id`, `role_id`) VALUES
(9, 5),
(9, 7);

-- --------------------------------------------------------

--
-- Table structure for table `webhooks`
--

CREATE TABLE `webhooks` (
  `id` int(10) UNSIGNED NOT NULL,
  `form_id` int(10) UNSIGNED NOT NULL,
  `url` varchar(2048) NOT NULL,
  `secret` varchar(255) DEFAULT NULL COMMENT 'HMAC secret pour signature X-KBForms-Signature',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `answers`
--
ALTER TABLE `answers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_answers_response_id` (`response_id`),
  ADD KEY `idx_answers_question_id` (`question_id`);

--
-- Indexes for table `conditions`
--
ALTER TABLE `conditions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_conditions_form` (`form_id`),
  ADD KEY `idx_conditions_qsrc` (`source_question_id`);

--
-- Indexes for table `forms`
--
ALTER TABLE `forms`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `form_cache`
--
ALTER TABLE `form_cache`
  ADD PRIMARY KEY (`token`),
  ADD KEY `idx_cache_expires` (`expires_at`);

--
-- Indexes for table `form_collaborators`
--
ALTER TABLE `form_collaborators`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_collab` (`form_id`,`user_id`),
  ADD KEY `idx_collab_form` (`form_id`),
  ADD KEY `idx_collab_user` (`user_id`);

--
-- Indexes for table `form_google_sheets`
--
ALTER TABLE `form_google_sheets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_form_sheet` (`form_id`);

--
-- Indexes for table `options`
--
ALTER TABLE `options`
  ADD PRIMARY KEY (`id`),
  ADD KEY `question_id` (`question_id`);

--
-- Indexes for table `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `questions`
--
ALTER TABLE `questions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `form_id` (`form_id`),
  ADD KEY `idx_question_repeat_group` (`repeat_group_id`);

--
-- Indexes for table `responses`
--
ALTER TABLE `responses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_responses_form_id` (`form_id`),
  ADD KEY `idx_responses_agent` (`agent_id`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD PRIMARY KEY (`role_id`,`permission_id`),
  ADD KEY `permission_id` (`permission_id`);

--
-- Indexes for table `sections`
--
ALTER TABLE `sections`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sections_form` (`form_id`,`position`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `user_roles`
--
ALTER TABLE `user_roles`
  ADD PRIMARY KEY (`user_id`,`role_id`),
  ADD KEY `role_id` (`role_id`);

--
-- Indexes for table `webhooks`
--
ALTER TABLE `webhooks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_webhooks_form` (`form_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `answers`
--
ALTER TABLE `answers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=118;

--
-- AUTO_INCREMENT for table `conditions`
--
ALTER TABLE `conditions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `forms`
--
ALTER TABLE `forms`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `form_collaborators`
--
ALTER TABLE `form_collaborators`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `form_google_sheets`
--
ALTER TABLE `form_google_sheets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `options`
--
ALTER TABLE `options`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=86;

--
-- AUTO_INCREMENT for table `permissions`
--
ALTER TABLE `permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8001;

--
-- AUTO_INCREMENT for table `questions`
--
ALTER TABLE `questions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=162;

--
-- AUTO_INCREMENT for table `responses`
--
ALTER TABLE `responses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1204;

--
-- AUTO_INCREMENT for table `sections`
--
ALTER TABLE `sections`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `webhooks`
--
ALTER TABLE `webhooks`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `answers`
--
ALTER TABLE `answers`
  ADD CONSTRAINT `answers_ibfk_1` FOREIGN KEY (`response_id`) REFERENCES `responses` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `answers_ibfk_2` FOREIGN KEY (`question_id`) REFERENCES `questions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `conditions`
--
ALTER TABLE `conditions`
  ADD CONSTRAINT `conditions_ibfk_1` FOREIGN KEY (`form_id`) REFERENCES `forms` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `conditions_ibfk_2` FOREIGN KEY (`source_question_id`) REFERENCES `questions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `form_google_sheets`
--
ALTER TABLE `form_google_sheets`
  ADD CONSTRAINT `fk_fgs_form` FOREIGN KEY (`form_id`) REFERENCES `forms` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `options`
--
ALTER TABLE `options`
  ADD CONSTRAINT `options_ibfk_1` FOREIGN KEY (`question_id`) REFERENCES `questions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `questions`
--
ALTER TABLE `questions`
  ADD CONSTRAINT `questions_ibfk_1` FOREIGN KEY (`form_id`) REFERENCES `forms` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_question_repeat_group` FOREIGN KEY (`repeat_group_id`) REFERENCES `repeat_groups` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `repeat_groups`
--
ALTER TABLE `repeat_groups`
  ADD CONSTRAINT `fk_repeat_group_form` FOREIGN KEY (`form_id`) REFERENCES `forms` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `responses`
--
ALTER TABLE `responses`
  ADD CONSTRAINT `responses_ibfk_1` FOREIGN KEY (`form_id`) REFERENCES `forms` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_responses_agent` FOREIGN KEY (`agent_id`) REFERENCES `form_agents` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD CONSTRAINT `role_permissions_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `role_permissions_ibfk_2` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sections`
--
ALTER TABLE `sections`
  ADD CONSTRAINT `sections_ibfk_1` FOREIGN KEY (`form_id`) REFERENCES `forms` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_roles`
--
ALTER TABLE `user_roles`
  ADD CONSTRAINT `user_roles_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_roles_ibfk_2` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
