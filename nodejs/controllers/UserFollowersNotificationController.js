const funcs = require('../functions/functions')
const compiledTemplates = require('../compiledTemplates/compiledTemplates')
const socketEvents = require('../events/events')
const { Sequelize, Op, DataTypes } = require("sequelize");
const striptags = require('striptags');
const moment = require("moment")

const UserFollowersNotificationController = async (ctx, data, io,socket) => {
  let user_id = ctx.userHashUserId[data.user_id]
  let followers = await ctx.wo_followers.findAll({
      attributes: ["follower_id"],
      where: {
          following_id: user_id,
          follower_id: {
              [Op.not]: user_id
          }
      },
      raw: true
  })
  for (let follow of followers) {
      await io.to(follow.follower_id).emit("new_notification", { user_id: user_id })
  }
};

module.exports = { UserFollowersNotificationController };