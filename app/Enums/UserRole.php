<?php

namespace App\Enums;

enum UserRole: string
{
    case CEO = 'CEO';
    case ContentCreator = 'Content Creator';
    case GraphicDesigner = 'Graphic Designer';
    case MSO = 'MSO';
    case Admin = 'Admin';
    case ClientOwner = 'Client Owner';
    case ClientMember = 'Client Member';
}